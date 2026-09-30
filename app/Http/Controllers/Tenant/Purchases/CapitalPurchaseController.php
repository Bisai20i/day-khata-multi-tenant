<?php

namespace App\Http\Controllers\Tenant\Purchases;

use App\Enums\DepreciationMethod;
use App\Enums\DepreciationPool;
use App\Exports\CapitalPurchaseListExport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CapitalPurchase;
use App\Models\CompanySetting;
use App\Models\PrintLog;
use App\Models\Store;
use App\Models\Supplier;
use App\Support\AmountInWords;
use App\Support\Billing\BillingException;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;

class CapitalPurchaseController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Purchases/CapitalPurchases/Index', [
            'capitalPurchases' => CapitalPurchase::query()
                ->with(['supplier:id,name,tpin', 'lines.account:id,code,name', 'journalVoucher:id,voucher_number', 'settlements', 'canceller:id,name'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get()
                ->each(function (CapitalPurchase $capitalPurchase): void {
                    $capitalPurchase->setAttribute('outstanding_amount', $capitalPurchase->outstandingAmount()->toString());
                }),
            // Exact SQL sum over every capital purchase (item 8, "totals
            // row") - never a page's worth of client-side addition. Cancelled
            // bills stay listed but never move the total (flags G-02).
            'totals' => [
                'total' => Money::round(
                    CapitalPurchase::query()->where('status', '!=', 'cancelled')->toBase()->selectRaw('COALESCE(SUM(total), 0) as total')->value('total')
                )->toString(),
            ],
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'tpin']),
            'accounts' => Account::query()->orderBy('name')->get(['id', 'code', 'name']),
            'canCancel' => request()->user()?->role?->slug === 'admin',
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'defaultVatRate' => (string) (CompanySetting::current()->default_vat_rate ?? '13.00'),
            // Asset register (item 5): only accounts filed under Fixed
            // Assets can create a FixedAsset from their line.
            'depreciationCategories' => array_map(fn (DepreciationPool $pool) => $pool->value, DepreciationPool::cases()),
            'depreciationMethods' => array_map(fn (DepreciationMethod $method) => $method->value, DepreciationMethod::cases()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'supplier_pan' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:capital,service'],
            'bill_number' => ['nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'narration' => ['nullable', 'string', 'max:255'],
            'payment_mode' => ['required', 'in:cash,bank,partial,credit'],
            'bank_account_id' => ['nullable', 'exists:accounts,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'cash_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'bank_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            // The company rate or 0, nothing typed in between (flags G-03);
            // the model re-checks inside post().
            'vat_rate' => ['nullable', 'numeric', 'decimal:0,2', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    CapitalPurchase::assertAllowedVatRate($value);
                } catch (InvalidArgumentException $e) {
                    $fail($e->getMessage());
                }
            }],
            'expected_total' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'exists:accounts,id'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'lines.*.vatable' => ['nullable', 'boolean'],
            'lines.*.narration' => ['nullable', 'string', 'max:255'],
            // Asset register (item 5): optionally turns a "capital" line
            // into a tracked, depreciating FixedAsset. CapitalPurchase::
            // createAssetForLine() re-checks the account is actually filed
            // under Fixed Assets - this only catches obviously wrong enum
            // values before the transaction.
            'lines.*.create_asset' => ['nullable', 'boolean'],
            'lines.*.asset_name' => ['nullable', 'string', 'max:255'],
            'lines.*.depreciation_category' => ['nullable', Rule::in(array_map(fn (DepreciationPool $pool) => $pool->value, DepreciationPool::cases()))],
            'lines.*.depreciation_method' => ['nullable', Rule::in(array_map(fn (DepreciationMethod $method) => $method->value, DepreciationMethod::cases()))],
            'lines.*.depreciation_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'lines.*.salvage_value' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        // The model holds the authoritative rule (and the unique index behind
        // it) because only it can check inside the posting transaction; this
        // rule catches the ordinary case with a field-level message before the
        // form is even submitted twice.
        $this->assertBillNumberIsNew($data);

        try {
            $capitalPurchase = CapitalPurchase::post($data, $data['lines'], $request->user());
        } catch (BillingException $e) {
            // C8: the browser previewed a total with money.js and sent it back.
            // A difference means the bill on screen was not the bill being
            // saved, so the save is refused rather than booking another amount.
            throw ValidationException::withMessages(
                $e->reason === BillingException::REASON_TOTAL_MISMATCH
                    ? ['expected_total' => 'The bill total changed. Please review it before saving.']
                    : ['lines' => $e->getMessage()]
            );
        } catch (AuthorizationException $e) {
            return back()->withErrors(['date' => $e->getMessage()])->withInput();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.capital-purchases.index')
            ->with('status', 'Capital purchase posted.')
            ->with('created', [
                'type' => 'capital-purchase',
                'id' => $capitalPurchase->id,
            ]);
    }

    /**
     * Excel export of the full capital purchase list (item 8) - the Index
     * page has no server-side filters of its own, so this exports
     * everything, same as the page shows.
     */
    public function export()
    {
        $capitalPurchases = CapitalPurchase::query()
            ->with(['supplier:id,name', 'canceller:id,name'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $rows = $capitalPurchases->values()->map(fn (CapitalPurchase $capitalPurchase, int $index): array => [
            'sn' => $index + 1,
            'date' => $capitalPurchase->date->toDateString(),
            'bill_number' => $capitalPurchase->bill_number,
            'type' => ucfirst($capitalPurchase->type),
            'supplier' => $capitalPurchase->supplier?->name,
            'payment_mode' => ucfirst($capitalPurchase->payment_mode),
            'total' => $capitalPurchase->total,
            'status' => $capitalPurchase->status === 'cancelled' ? 'Cancelled' : 'Posted',
            'cancelled_on' => $capitalPurchase->cancelled_at?->toDateString(),
            'cancelled_by' => $capitalPurchase->canceller?->name,
            'cancel_reason' => $capitalPurchase->cancel_reason,
        ]);

        $total = Money::sum($capitalPurchases
            ->reject(fn (CapitalPurchase $capitalPurchase): bool => $capitalPurchase->status === 'cancelled')
            ->map(fn (CapitalPurchase $capitalPurchase): Money => Money::of($capitalPurchase->total)))->toString();

        return Excel::download(new CapitalPurchaseListExport($rows, $total), 'capital-purchases.xlsx');
    }

    /**
     * Printable capital or service bill (flags G-01): lines, VAT split,
     * payment, the payments made against it and what is still owed. Every
     * print is logged, so a reprint says "Copy of Original" (CONTRACTS C9).
     * A cancelled bill still prints, marked with who cancelled it, when and
     * why.
     */
    public function print(Request $request, CapitalPurchase $capitalPurchase): HttpResponse
    {
        $capitalPurchase->load([
            'supplier', 'bankAccount', 'lines.account', 'journalVoucher.fiscalYear', 'canceller:id,name',
            'settlements' => fn ($query) => $query->with('bankAccount')->orderBy('date')->orderBy('id'),
        ]);

        $documentDate = $capitalPurchase->date->toDateString();
        $total = Money::of($capitalPurchase->total);
        $voucherNumber = $capitalPurchase->journalVoucher?->voucher_number;

        return Pdf::loadView('pdf.capital-purchase', [
            'capitalPurchase' => $capitalPurchase,
            'company' => CompanySetting::current(),
            'documentNumber' => $capitalPurchase->bill_number ?? ($voucherNumber !== null ? "Voucher #{$voucherNumber}" : "#{$capitalPurchase->id}"),
            'documentDate' => $documentDate,
            'paymentModeLabel' => match ($capitalPurchase->payment_mode) {
                'partial' => 'Cash + bank',
                default => ucfirst($capitalPurchase->payment_mode),
            },
            'taxable' => Money::of($capitalPurchase->taxable_amount),
            'nontaxable' => Money::of($capitalPurchase->nontaxable_amount),
            'vat' => Money::of($capitalPurchase->vat_amount),
            'total' => $total,
            'outstanding' => $capitalPurchase->outstandingAmount(),
            'settlements' => $capitalPurchase->settlements,
            'copyNumber' => PrintLog::record($capitalPurchase, $request->user()),
            'dateAd' => $documentDate,
            'dateBs' => NepaliCalendar::formatBs($documentDate),
            'fiscalYearName' => $capitalPurchase->journalVoucher?->fiscalYear?->name,
            'amountInWords' => AmountInWords::rupees($total),
        ])->stream("capital-purchase-{$capitalPurchase->id}.pdf");
    }

    public function cancel(Request $request, CapitalPurchase $capitalPurchase): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $capitalPurchase->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.capital-purchases.index')->with('status', 'Capital purchase cancelled.');
    }

    /**
     * @param  array{supplier_id?: int|null, bill_number?: string|null}  $data
     */
    private function assertBillNumberIsNew(array $data): void
    {
        $guard = CapitalPurchase::billNumberGuard(
            isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            $data['bill_number'] ?? null,
        );

        if ($guard === null) {
            return;
        }

        if (CapitalPurchase::query()->where('bill_number_guard', $guard)->exists()) {
            throw ValidationException::withMessages([
                'bill_number' => 'This bill number has already been entered for this supplier.',
            ]);
        }
    }
}
