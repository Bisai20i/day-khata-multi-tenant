<?php

namespace App\Http\Controllers\Tenant\Purchases;

use App\Exports\PurchaseListExport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PrintLog;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\Supplier;
use App\Support\AmountInWords;
use App\Support\Billing\BillingException;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;

class PurchaseController extends Controller
{
    /**
     * Listing is server-side filtered (date range + supplier) and paginated
     * - same `when()`/`paginate()->withQueryString()` shape
     * Central\Tenants\TenantController::index() established, so it stays
     * consistent across the app rather than loading every purchase
     * unfiltered (a real usability problem once invoice history grows).
     */
    public function index(Request $request): Response
    {
        $from = $request->filled('from') ? $request->string('from')->toString() : null;
        $to = $request->filled('to') ? $request->string('to')->toString() : null;
        $supplierId = $request->filled('supplier_id') ? (int) $request->input('supplier_id') : null;

        $purchases = $this->filteredPurchasesQuery($from, $to, $supplierId)
            ->with(['supplier:id,name', 'lines.item:id,name,unit', 'journalVoucher:id,voucher_number'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $company = CompanySetting::current();

        return Inertia::render('Tenant/Purchases/Index', [
            'purchases' => $purchases,
            'filters' => [
                'from' => $from,
                'to' => $to,
                'supplier_id' => $supplierId,
            ],
            // Exact SQL sums over the whole filtered set (item 8, "totals
            // row") - never a page's worth of client-side addition.
            'totals' => $this->filteredTotals($from, $to, $supplierId),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'mobile_no', 'is_vat_registered']),
            // Inactive items are deliberately withheld: an item that has been
            // retired must not be purchasable again from the form, and showing
            // it only to have the post rejected is worse than not offering it.
            'items' => Item::query()->where('is_active', true)->orderBy('name')
                ->with(['units' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
                ->get(['id', 'name', 'unit', 'purchase_rate', 'is_vatable', 'is_stockable', 'barcode']),
            // For the quick add-item link (item 9): posting a new item needs
            // a category, so the create form offers the same short list
            // Items/Index.vue uses rather than requiring a trip to that page.
            'itemCategories' => ItemCategory::query()->orderBy('name')->get(['id', 'name']),
            // Two narrow pickers instead of one list of every account in the
            // chart: money can only leave through an asset account, and TDS can
            // only be withheld into a liability.
            'bankAccounts' => $this->accountsUnderHead('Assets'),
            'tdsAccounts' => $this->accountsUnderHead('Liabilities'),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'settings' => [
                'default_vat_rate' => $company->default_vat_rate,
                'default_store_id' => $company->default_store_id,
            ],
            // The one closed year currently reopened for correction, if
            // any - lets Create.vue offer it as the only non-current
            // fiscal-year option, per the locked design decision (see
            // ClosedFiscalYearGuard's docblock). Null hides the picker
            // entirely, same as before this feature existed.
            'correctionFiscalYear' => FiscalYear::openForCorrection()?->only(['id', 'name', 'reopen_reason']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            // One LIVE purchase per (supplier, bill number). `bill_number_key`
            // carries the number only while the purchase is live, so cancelling
            // a bill frees its number again (see the migration). This rule is
            // what puts the message on the Bill Number field; Purchase::post()
            // repeats the check inside its transaction, behind the supplier row
            // lock, for the two-clerks-at-once race this cannot see.
            'bill_number' => [
                'nullable', 'string', 'max:255',
                Rule::unique('purchases', 'bill_number_key')->where('supplier_id', $request->input('supplier_id')),
            ],
            'pan_number' => ['nullable', 'string', 'max:50'],
            'chalani_number' => ['nullable', 'string', 'max:100'],
            'date' => ['required', 'date'],
            'payment_mode' => ['required', 'in:cash,bank,partial,credit'],
            'bank_account_id' => ['nullable', 'exists:accounts,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            // decimal:0,N mirrors the column: a value with more decimals than
            // the column can hold used to be charged for and then silently
            // rounded on insert (audit P0-5).
            'discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'discount_type' => ['nullable', 'in:percentage,flat'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            // PAN / non-VAT purchase mode (item 2): every line lands in the
            // exempt column of the Purchase VAT book. Omitted (not merely
            // false) lets Purchase::post() fall back to the supplier's own
            // is_vat_registered flag.
            'force_non_taxable' => ['nullable', 'boolean'],
            'cash_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'bank_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'tds_account_id' => ['nullable', 'exists:accounts,id'],
            // TDS (item 1): a rate takes precedence over a typed amount -
            // Purchase::post() computes the amount itself as
            // (taxable + nontaxable) x rate. `max:100` is what keeps that
            // product inside the base the calculator allows (CONTRACTS C3
            // step 8), so a nonsense rate is a field error here rather than a
            // rejected bill further in.
            'tds_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'tds_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'expected_total' => ['nullable', 'numeric', 'decimal:0,2'],
            'narration' => ['nullable', 'string', 'max:255'],
            'fiscal_year_id' => ['nullable', 'integer', 'exists:fiscal_years,id'],
            'reason' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            // Null/omitted means the item's own base unit - see
            // SaleController::store()'s identical rule for the full
            // rationale (Purchase::post() owns the cross-item ownership
            // check).
            'lines.*.item_unit_id' => ['nullable', 'integer', 'exists:item_units,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001', 'decimal:0,4'],
            // Bonus / free quantity (item 3): extra units received at no
            // charge, added to the stock movement only - never billed.
            'lines.*.bonus_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'lines.*.rate' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'lines.*.discount_type' => ['nullable', 'in:percentage,flat'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ], [
            'bill_number.unique' => 'This bill number has already been entered for this supplier. '
                .'Cancel that purchase first if this one replaces it.',
        ]);

        try {
            $purchase = Purchase::post($data, $data['lines'], $request->user());
        } catch (BillingException $e) {
            // The browser sent the total it showed the user; anything else here
            // means the bill on screen was not the bill about to be saved
            // (CONTRACTS C8). Every other calculator complaint is a line error.
            throw ValidationException::withMessages(
                $e->reason === BillingException::REASON_TOTAL_MISMATCH
                    ? ['expected_total' => 'The bill total changed. Please review it before saving.']
                    : ['lines' => $e->getMessage()]
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        } catch (AuthorizationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()])->withInput();
        }

        // CONTRACTS C11: the page opens this print URL itself instead of
        // guessing the newest id off the list, which printed the wrong bill for
        // a back-dated entry or a second terminal (audit P0-6).
        return redirect()->route('tenant.purchases.index')
            ->with('status', 'Purchase posted.')
            ->with('created', [
                'type' => 'purchase',
                'id' => $purchase->id,
                'print_url' => route('tenant.purchases.print', $purchase),
            ]);
    }

    /**
     * Excel export of the purchase list (item 8), honouring the same
     * from/to/supplier filters the Index page's own list uses - never a
     * client recomputation, the exported rows are the stored server values.
     */
    public function export(Request $request)
    {
        $from = $request->filled('from') ? $request->string('from')->toString() : null;
        $to = $request->filled('to') ? $request->string('to')->toString() : null;
        $supplierId = $request->filled('supplier_id') ? (int) $request->input('supplier_id') : null;

        $purchases = $this->filteredPurchasesQuery($from, $to, $supplierId)
            ->with(['supplier:id,name'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $rows = $purchases->values()->map(fn (Purchase $purchase, int $index): array => [
            'sn' => $index + 1,
            'date' => $purchase->date->toDateString(),
            'supplier' => $purchase->supplier?->name,
            'bill_number' => $purchase->bill_number,
            'payment_mode' => ucfirst((string) $purchase->payment_mode),
            'total' => $purchase->total,
            'status' => $purchase->status === 'cancelled' ? 'Cancelled' : 'Posted',
        ]);

        $total = Money::sum($purchases->map(fn (Purchase $purchase): Money => Money::of($purchase->total)))->toString();

        return Excel::download(new PurchaseListExport($rows, $total), 'purchases.xlsx');
    }

    public function cancel(Request $request, Purchase $purchase): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $purchase->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.purchases.index')->with('status', 'Purchase cancelled.');
    }

    /**
     * Streams a printable PDF purchase bill inline (not a forced download), so
     * it opens in a new browser tab from a plain anchor link on the Index page.
     *
     * Every reprint is logged, and the copy number it returns is what makes the
     * layout stamp "Copy of Original" on anything after the first (CONTRACTS C9).
     */
    public function print(Request $request, Purchase $purchase): HttpResponse
    {
        $purchase->load(['supplier', 'bankAccount', 'lines.item', 'lines.itemUnit', 'journalVoucher.fiscalYear']);

        $company = CompanySetting::current();

        $documentNumber = $purchase->journalVoucher
            ? "{$company->purchase_prefix}-{$purchase->journalVoucher->voucher_number}"
            : "{$company->purchase_prefix}-{$purchase->id}";

        return Pdf::loadView('pdf.purchase', [
            'purchase' => $purchase,
            'company' => $company,
            'documentNumber' => $documentNumber,
            'documentDate' => $purchase->date->format('Y-m-d'),
            'copyNumber' => PrintLog::record($purchase, $request->user()),
            'dateAd' => $purchase->date->format('Y-m-d'),
            'dateBs' => NepaliCalendar::formatBs($purchase->date),
            'fiscalYearName' => $purchase->journalVoucher?->fiscalYear?->name,
            'amountInWords' => AmountInWords::rupees(Money::of($purchase->total)),
        ])->stream("purchase-{$purchase->id}.pdf");
    }

    /**
     * @return Builder<Purchase>
     */
    private function filteredPurchasesQuery(?string $from, ?string $to, ?int $supplierId)
    {
        return Purchase::query()
            ->when($from, fn ($query, string $from) => $query->whereDate('date', '>=', $from))
            ->when($to, fn ($query, string $to) => $query->whereDate('date', '<=', $to))
            ->when($supplierId, fn ($query, int $supplierId) => $query->where('supplier_id', $supplierId));
    }

    /**
     * @return array<string, string>
     */
    private function filteredTotals(?string $from, ?string $to, ?int $supplierId): array
    {
        $row = $this->filteredPurchasesQuery($from, $to, $supplierId)->toBase()->selectRaw(
            'COALESCE(SUM(taxable_amount), 0) as taxable_amount, '
            .'COALESCE(SUM(nontaxable_amount), 0) as nontaxable_amount, '
            .'COALESCE(SUM(vat_amount), 0) as vat_amount, '
            .'COALESCE(SUM(total), 0) as total'
        )->first();

        return [
            'taxable_amount' => Money::round($row->taxable_amount)->toString(),
            'nontaxable_amount' => Money::round($row->nontaxable_amount)->toString(),
            'vat_amount' => Money::round($row->vat_amount)->toString(),
            'total' => Money::round($row->total)->toString(),
        ];
    }

    /**
     * Accounts filed anywhere under one head of the chart, so the bank and TDS
     * pickers offer only accounts that can legitimately be used there instead
     * of every account in the business.
     *
     * @return Collection<int, Account>
     */
    private function accountsUnderHead(string $head): Collection
    {
        return Account::query()
            ->where(fn ($query) => $query
                ->whereHas('group.accountHead', fn ($q) => $q->where('name', $head))
                ->orWhereHas('subgroup.accountGroup.accountHead', fn ($q) => $q->where('name', $head)))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }
}
