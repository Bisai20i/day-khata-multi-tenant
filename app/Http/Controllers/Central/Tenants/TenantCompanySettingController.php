<?php

namespace App\Http\Controllers\Central\Tenants;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\VoucherSequence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Manages a tenant's own CompanySetting singleton from the central (platform
 * admin) panel - tenants have no access to their own settings any more (see
 * the now-deleted App\Http\Controllers\Tenant\Admin\SettingsController and
 * routes/tenant-settings.php). Every read/write here runs against the
 * target tenant's own database via Tenant::run() (same pattern as
 * TenantUserController::index()), which switches the DB connection and
 * initializes tenancy - so tenant()/tenant('id') helpers still resolve
 * correctly - for the duration of the closure.
 */
class TenantCompanySettingController extends Controller
{
    /**
     * The 'public' disk is never made tenant-aware (see config/tenancy.php's
     * bootstrappers docblock - FilesystemTenancyBootstrapper is deliberately
     * disabled), so every tenant shares the same physical storage/app/public
     * root. Logos are namespaced under tenant-logos/{tenant_id}/ manually to
     * avoid collisions between tenants, mirroring ItemController's own
     * storeImage() convention for the same reason.
     */
    private const LOGO_DISK = 'public';

    /**
     * Every independently numbered, customer- or supplier-facing document
     * series: the settings column holding its prefix, and the VoucherType
     * whose sequence hands out its numbers.
     *
     * Two series must never share a prefix - "SL-7" printed on two different
     * documents is indistinguishable on paper and in the VAT book - so these
     * columns are validated as a distinct set in update(), and this is also
     * the list the "Invoice numbering" panel walks to show each series' next
     * number.
     *
     * @var array<string, array{label: string, type: VoucherType}>
     */
    private const NUMBERED_SERIES = [
        'sale_full_prefix' => ['label' => 'Full tax invoice', 'type' => VoucherType::Sale],
        'sale_abbreviated_prefix' => ['label' => 'Abbreviated tax invoice', 'type' => VoucherType::SaleAbbreviated],
        'sale_pan_prefix' => ['label' => 'PAN invoice', 'type' => VoucherType::SalePan],
        'purchase_prefix' => ['label' => 'Purchase', 'type' => VoucherType::Purchase],
        'sale_return_prefix' => ['label' => 'Credit note (sales return)', 'type' => VoucherType::SaleReturn],
        'purchase_return_prefix' => ['label' => 'Debit note (purchase return)', 'type' => VoucherType::PurchaseReturn],
    ];

    public function edit(Tenant $tenant): Response|RedirectResponse
    {
        // See TenantController::impersonate() for why this is checked
        // explicitly rather than caught as an exception from run() itself.
        if (! $tenant->databaseExists()) {
            return redirect()
                ->route('central.tenants.show', $tenant)
                ->with('status', "This tenant's database doesn't exist yet - use \"Re-provision database\" first.");
        }

        // Every value returned here must be a plain array, not an Eloquent
        // model/collection: Inertia doesn't serialize props until the router
        // converts the response (see PropsResolver), which happens after
        // this run() closure returns and tenancy has already ended. A model
        // fetched on the 'tenant' connection still carries that connection
        // name, so serializing it later (e.g. its timestamps) would reach
        // for a connection reconnectToCentral() already purged, throwing
        // "Database connection [tenant] not configured."
        [$settings, $stores, $invoiceNumbering] = $tenant->run(function () {
            $settings = CompanySetting::current();
            $invoiceNumbering = $this->invoiceNumbering($settings);

            return [
                $settings->toArray(),
                Store::where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray(),
                $invoiceNumbering,
            ];
        });

        return Inertia::render('Central/Tenants/Settings/Edit', [
            'tenant' => [
                'id' => $tenant->id,
                'company_name' => $tenant->company_name,
            ],
            'settings' => $settings,
            'stores' => $stores,
            'invoiceNumbering' => $invoiceNumbering,
        ]);
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'pan_vat_number' => ['nullable', 'string', 'max:255'],
            'invoice_footer_note' => ['nullable', 'string', 'max:2000'],
            'print_paper_size' => ['nullable', 'in:a4,a5,58mm,80mm'],
            // decimal:0,2 on top of the range check: default_vat_rate is cast
            // through App\Casts\Decimal, which throws rather than silently
            // rounding a third decimal away, so the form has to reject 13.005
            // with a field error instead of letting it reach the model.
            'default_vat_rate' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'allow_negative_stock' => ['boolean'],
            // Plain 'exists:stores,id' would run against the central
            // connection, which has no stores table at all - stores only
            // exist per-tenant. Check existence inside the tenant's own
            // database instead.
            'default_store_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($tenant): void {
                    if ($tenant->run(fn () => ! Store::whereKey($value)->exists())) {
                        $fail('The selected store does not exist for this tenant.');
                    }
                },
            ],
            'sale_full_prefix' => ['required', 'string', 'max:20'],
            'sale_abbreviated_prefix' => ['required', 'string', 'max:20'],
            'sale_pan_prefix' => ['required', 'string', 'max:20'],
            // The invoice type a business issues is fixed by how it's
            // registered with IRD, never a per-sale cashier choice - one
            // active type only, set here by the platform admin (see
            // App\Models\Sale::post()).
            'active_invoice_type' => ['required', 'in:full,abbreviated,pan'],
            'purchase_prefix' => ['required', 'string', 'max:20'],
            'sale_return_prefix' => ['required', 'string', 'max:20'],
            'purchase_return_prefix' => ['required', 'string', 'max:20'],
        ]);

        $this->assertPrefixesAreDistinct($data);

        $tenant->run(fn () => CompanySetting::current()->update($data));

        return redirect()->route('central.tenants.settings.edit', $tenant)->with('status', 'Settings updated.');
    }

    /**
     * Sets the number the next document of one series will be issued under, so
     * a tenant migrating mid-year from another system carries on from where
     * their old books stopped instead of restarting at 1 (audit P1, "No
     * starting invoice number setting").
     *
     * VoucherSequence::setStartingNumber() refuses once that series has
     * numbered anything in the year - moving the counter after that either
     * duplicates a printed number or leaves a hole in a legally gapless
     * series.
     */
    public function setStartingNumber(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'voucher_type' => ['required', Rule::in($this->numberedVoucherTypeValues())],
            'next_number' => ['required', 'integer', 'min:1', 'max:99999999'],
        ]);

        $error = $tenant->run(function () use ($data) {
            $fiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

            if (! $fiscalYear) {
                return 'No fiscal year is open, so there is nothing to number yet.';
            }

            try {
                VoucherSequence::setStartingNumber($fiscalYear, VoucherType::from($data['voucher_type']), $data['next_number']);
            } catch (InvalidArgumentException $e) {
                return $e->getMessage();
            }

            return null;
        });

        if ($error !== null) {
            return back()->withErrors(['next_number' => $error]);
        }

        return redirect()->route('central.tenants.settings.edit', $tenant)->with('status', 'Starting number updated.');
    }

    /**
     * Kept separate from update() so uploading a logo doesn't require
     * re-submitting (and re-validating) the whole settings form.
     */
    public function uploadLogo(Request $request, Tenant $tenant): RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'max:2048'],
        ]);

        $tenant->run(function () use ($request, $tenant): void {
            $settings = CompanySetting::current();

            if ($settings->logo_path) {
                Storage::disk(self::LOGO_DISK)->delete($settings->logo_path);
            }

            $path = $request->file('logo')->store('tenant-logos/'.$tenant->id, self::LOGO_DISK);

            $settings->update(['logo_path' => $path]);
        });

        return redirect()->route('central.tenants.settings.edit', $tenant)->with('status', 'Logo updated.');
    }

    /**
     * Compared case-insensitively and trimmed: "sl" and "SL " print the same
     * thing, so treating them as different would defeat the check.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertPrefixesAreDistinct(array $data): void
    {
        $seen = [];
        $duplicates = [];

        foreach (array_keys(self::NUMBERED_SERIES) as $column) {
            $normalised = strtolower(trim((string) $data[$column]));

            if (isset($seen[$normalised])) {
                $duplicates[$column] = 'Each document series needs its own prefix; this one is already used by another series.';

                continue;
            }

            $seen[$normalised] = $column;
        }

        if ($duplicates !== []) {
            throw ValidationException::withMessages($duplicates);
        }
    }

    /**
     * The next number each series will issue in the open fiscal year, plus
     * whether that number can still be changed. Must run inside the
     * tenant's own Tenant::run() closure (see callers) - it queries
     * FiscalYear/JournalVoucher/VoucherSequence, all tenant-scoped models.
     *
     * @return array<int, array{voucher_type: string, label: string, prefix: string, next_number: int, can_set: bool, fiscal_year: string|null}>
     */
    private function invoiceNumbering(CompanySetting $settings): array
    {
        $fiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

        $rows = [];

        foreach (self::NUMBERED_SERIES as $column => $series) {
            $nextNumber = $fiscalYear ? VoucherSequence::nextNumberFor($fiscalYear, $series['type']) : 1;

            $rows[] = [
                'voucher_type' => $series['type']->value,
                'label' => $series['label'],
                'prefix' => (string) $settings->{$column},
                'next_number' => $nextNumber,
                'can_set' => $fiscalYear !== null && ! JournalVoucher::query()
                    ->where('fiscal_year_id', $fiscalYear->id)
                    ->where('voucher_type', $series['type'])
                    ->exists(),
                'fiscal_year' => $fiscalYear?->name,
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function numberedVoucherTypeValues(): array
    {
        return array_map(
            static fn (array $series): string => $series['type']->value,
            array_values(self::NUMBERED_SERIES),
        );
    }
}
