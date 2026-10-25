<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Admin;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\Store;
use App\Models\VoucherSequence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class CompanySettingController extends Controller
{
    private const LOGO_DISK = 'public';

    /**
     * Every independently numbered, customer- or supplier-facing document
     * series: the settings column holding its prefix, and the VoucherType
     * whose sequence hands out its numbers.
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

    public function edit(): Response
    {
        $settings = CompanySetting::current();
        $stores = Store::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $invoiceNumbering = $this->invoiceNumbering($settings);

        return Inertia::render('Tenant/Admin/Settings/Edit', [
            'settings' => $settings,
            'stores' => $stores,
            'invoiceNumbering' => $invoiceNumbering,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'pan_vat_number' => ['nullable', 'string', 'max:255'],
            'invoice_footer_note' => ['nullable', 'string', 'max:2000'],
            'print_paper_size' => ['nullable', 'in:a4,a5,58mm,80mm'],
            'default_vat_rate' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'allow_negative_stock' => ['boolean'],
            'default_store_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && ! Store::whereKey($value)->exists()) {
                        $fail('The selected store does not exist.');
                    }
                },
            ],
            'sale_full_prefix' => ['required', 'string', 'max:20'],
            'sale_abbreviated_prefix' => ['required', 'string', 'max:20'],
            'sale_pan_prefix' => ['required', 'string', 'max:20'],
            'active_invoice_type' => ['required', 'in:full,abbreviated,pan'],
            'purchase_prefix' => ['required', 'string', 'max:20'],
            'sale_return_prefix' => ['required', 'string', 'max:20'],
            'purchase_return_prefix' => ['required', 'string', 'max:20'],
        ]);

        $this->assertPrefixesAreDistinct($data);

        CompanySetting::current()->update($data);

        return redirect()->route('tenant.settings.edit')->with('status', 'Company settings updated.');
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'max:2048'],
        ]);

        $settings = CompanySetting::current();

        if ($settings->logo_path) {
            Storage::disk(self::LOGO_DISK)->delete($settings->logo_path);
        }

        $path = $request->file('logo')->store('tenant-logos/'.tenant('id'), self::LOGO_DISK);

        $settings->update(['logo_path' => $path]);

        return redirect()->route('tenant.settings.edit')->with('status', 'Company logo updated.');
    }

    public function deleteLogo(): RedirectResponse
    {
        $settings = CompanySetting::current();

        if ($settings->logo_path) {
            Storage::disk(self::LOGO_DISK)->delete($settings->logo_path);
        }

        $settings->update(['logo_path' => null]);

        return redirect()->route('tenant.settings.edit')->with('status', 'Company logo removed.');
    }

    public function setStartingNumber(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'voucher_type' => ['required', Rule::in($this->numberedVoucherTypeValues())],
            'next_number' => ['required', 'integer', 'min:1', 'max:99999999'],
        ]);

        $fiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

        if (! $fiscalYear) {
            return back()->withErrors(['next_number' => 'No fiscal year is open, so there is nothing to number yet.']);
        }

        try {
            VoucherSequence::setStartingNumber($fiscalYear, VoucherType::from($data['voucher_type']), $data['next_number']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['next_number' => $e->getMessage()]);
        }

        return redirect()->route('tenant.settings.edit')->with('status', 'Starting number updated.');
    }

    /**
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
