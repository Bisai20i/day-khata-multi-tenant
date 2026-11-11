<?php

namespace App\Http\Controllers\Tenant\Purchases;

use App\Enums\FiscalYearStatus;
use App\Exports\PurchaseListExport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PrintLog;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\Supplier;
use App\Rules\AccountUnderHead;
use App\Support\AmountInWords;
use App\Support\Billing\BillingException;
use App\Support\Billing\RoundOffAccountMissing;
use App\Support\Billing\RoundOffPosting;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchFormOptions;
use App\Support\Branches\BranchListFilter;
use App\Support\Branches\BranchStoreOptions;
use App\Support\Inventory\ItemPurchasePriceUpdater;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;

class PurchaseController extends Controller
{
    /**
     * Columns the list may be sorted by, keyed by the `sort` query value - an
     * allow-list so a crafted value never reaches orderBy().
     *
     * @var array<string, string>
     */
    private const SORTABLE_COLUMNS = [
        'date' => 'date',
        'bill_number' => 'bill_number',
        'total' => 'total',
    ];

    /**
     * Listing is server-side filtered (date range + supplier + supplier bill
     * number search), sorted and paginated
     * - same `when()`/`paginate()->withQueryString()` shape
     * Central\Tenants\TenantController::index() established, so it stays
     * consistent across the app rather than loading every purchase
     * unfiltered (a real usability problem once invoice history grows).
     */
    public function index(Request $request): Response
    {
        $filters = $this->listFilters($request);

        // Branches (plan 12, BR12): the list, its totals row and the export
        // all follow the top-bar switcher through filteredPurchasesQuery();
        // each row names its branch only when several branches can appear.
        // A tenant that is not multi-branch gets the same query as before.
        $purchases = $this->filteredPurchasesQuery($filters, $request)
            ->with(['supplier:id,name', 'lines.item:id,name,unit', 'journalVoucher:id,voucher_number', 'canceller:id,name'])
            ->when(BranchListFilter::columnVisible($request), fn ($query) => $query->with('branch:id,name,code'))
            ->orderBy(self::SORTABLE_COLUMNS[$filters['sort']], $filters['sort_dir'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $company = CompanySetting::current();

        return Inertia::render('Tenant/Purchases/Index', [
            'purchases' => $purchases,
            'filters' => $filters,
            // Exact SQL sums over the whole filtered set (item 8, "totals
            // row") - never a page's worth of client-side addition.
            'totals' => $this->filteredTotals($filters, $request),
            // The Cancel action is gated by purchases.cancel at the route
            // (CONTRACTS C5); the page hides it for everyone else rather
            // than offering a 403.
            'canCancel' => (bool) $request->user()?->can('purchases.cancel'),
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
            'stores' => $this->storeOptions($request),
            'settings' => [
                'default_vat_rate' => $company->default_vat_rate,
                'default_store_id' => $company->default_store_id,
                'round_off_enabled' => $company->round_off_enabled,
                'round_off_precision' => $company->round_off_precision,
            ],
            // The one closed year currently reopened for correction, if
            // any - lets Create.vue offer it as the only non-current
            // fiscal-year option, per the locked design decision (see
            // ClosedFiscalYearGuard's docblock). Null hides the picker
            // entirely, same as before this feature existed.
            'correctionFiscalYear' => FiscalYear::openForCorrection()?->only(['id', 'name', 'reopen_reason']),
            // Branch picker of the create form (plan 12, BR12); hidden, and
            // nothing extra submitted, unless the company is multi-branch.
            ...BranchFormOptions::props($request),
        ]);
    }

    public function store(Request $request, ItemPurchasePriceUpdater $priceUpdater): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            // One LIVE purchase per (supplier, bill number). `bill_number_key`
            // carries the number only while the purchase is live, so cancelling
            // a bill frees its number again (see the migration). This rule is
            // what puts the message on the Bill Number field; Purchase::post()
            // repeats the check inside its transaction, behind the supplier row
            // lock, for the two-clerks-at-once race this cannot see.
            // Company-wide for a branch-restricted clerk too (plan 12): the
            // rule queries the table directly, not through the model, so the
            // branch access scope never hides a bill filed in another branch.
            'bill_number' => [
                'nullable', 'string', 'max:255',
                Rule::unique('purchases', 'bill_number_key')
                    ->where('supplier_id', $request->input('supplier_id'))
                    ->where('fiscal_year_id', $this->targetFiscalYearId($request)),
            ],
            'pan_number' => ['nullable', 'string', 'max:50'],
            'chalani_number' => ['nullable', 'string', 'max:100'],
            'date' => ['required', 'date'],
            'payment_mode' => ['required', 'in:cash,bank,partial,credit'],
            'bank_account_id' => ['nullable', 'exists:accounts,id', new AccountUnderHead('Assets')],
            // Retired stores and items cannot take new documents (flags G-13).
            'store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')->where('is_active', true)],
            // Optional (plan 12): Purchase::post() falls back to the store's
            // branch, then the user's posting branch, and refuses a store of
            // another branch.
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
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
            'tds_account_id' => ['nullable', 'exists:accounts,id', new AccountUnderHead('Liabilities')],
            // TDS (item 1): a rate takes precedence over a typed amount -
            // Purchase::post() computes the amount itself as
            // (taxable + nontaxable) x rate. `max:100` is what keeps that
            // product inside the base the calculator allows (CONTRACTS C3
            // step 8), so a nonsense rate is a field error here rather than a
            // rejected bill further in.
            'tds_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'tds_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            // Signed (+ adds to the bill, - reduces it), so deliberately no
            // `min`. Purchase::post() zeroes it unless the tenant enabled Round Off.
            'round_off' => ['nullable', 'numeric', 'decimal:0,2', 'between:-'.CompanySetting::ROUND_OFF_LIMIT.','.CompanySetting::ROUND_OFF_LIMIT],
            // Required (flags G-16): the total the user saw is what gets booked.
            'expected_total' => ['required', 'numeric', 'decimal:0,2'],
            'narration' => ['nullable', 'string', 'max:255'],
            'fiscal_year_id' => ['nullable', 'integer', 'exists:fiscal_years,id'],
            'reason' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', Rule::exists('items', 'id')->where('is_active', true)],
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
            // "Yes, update saved price" answers from the form, one per item:
            // {item_id, item_unit_id, rate}. Only the list itself is checked
            // here: it must be an array of at most 500 entries (the form sends
            // one per item on the bill), or the bill is refused. Deliberately
            // no per-entry rules - an entry that is malformed or not on this
            // bill is skipped by ItemPurchasePriceUpdater, never a reason to
            // reject the bill.
            'price_updates' => ['nullable', 'array', 'max:500'],
        ], [
            'bill_number.unique' => 'This bill number has already been entered for this supplier. '
                .'Cancel that purchase first if this one replaces it.',
        ]);

        try {
            // One transaction around both, so the saved prices change only
            // when this bill really posts: a rejected, failed or duplicate
            // bill rolls the price updates back with it. Purchase::post()
            // locks the supplier, then the bill's stock items, then the
            // fiscal year, so the updater's item locks are re-locks.
            $purchase = DB::transaction(function () use ($data, $request, $priceUpdater): Purchase {
                $purchase = Purchase::post($data, $data['lines'], $request->user());
                $priceUpdater->apply($purchase, $data['price_updates'] ?? []);

                return $purchase;
            });
        } catch (BillingException $e) {
            // The browser sent the total it showed the user; anything else here
            // means the bill on screen was not the bill about to be saved
            // (CONTRACTS C8). Every other calculator complaint is a line error.
            throw ValidationException::withMessages(match (true) {
                $e->reason === BillingException::REASON_TOTAL_MISMATCH => ['expected_total' => 'The bill total changed. Please review it before saving.'],
                // A negative Round Off that takes the bill to zero or below is the
                // Round Off field's problem, not a line's.
                $e->reason === BillingException::REASON_TOTAL_NOT_POSITIVE && RoundOffPosting::isNegativeInput($data['round_off'] ?? null) => ['round_off' => 'Round off cannot take the bill total to zero or below.'],
                default => ['lines' => $e->getMessage()],
            });
        } catch (RoundOffAccountMissing $e) {
            throw ValidationException::withMessages(['round_off' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            // A refused branch belongs on the branch picker and a store outside
            // the bill's branch on the store field (plan 12, BR12); everything
            // else stays under `lines` as before.
            return back()->withErrors([BranchFormOptions::errorField($e, 'lines', storeField: 'store_id') => $e->getMessage()])->withInput();
        } catch (AuthorizationException $e) {
            // The reopened-year guard stays on `reason`; a branch access
            // refusal raised this way goes to the branch picker.
            return back()->withErrors([BranchFormOptions::errorField($e, 'reason') => $e->getMessage()])->withInput();
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
     * from/to/supplier/search filters the Index page's own list uses - never
     * a client recomputation, the exported rows are the stored server values.
     * Rows stay in date order so the SN column reads chronologically.
     * `format=csv` streams a .csv instead of the default .xlsx - Laravel
     * Excel infers the writer from the filename extension. It also follows
     * the top-bar branch switcher, so the file holds exactly the rows the
     * list shows (plan 12, BR12).
     */
    public function export(Request $request)
    {
        $filters = $this->listFilters($request);
        $extension = $request->query('format') === 'csv' ? 'csv' : 'xlsx';

        $purchases = $this->filteredPurchasesQuery($filters, $request)
            ->with(['supplier:id,name', 'canceller:id,name'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $rows = $purchases->values()->map(fn (Purchase $purchase, int $index): array => [
            'sn' => $index + 1,
            'date' => $purchase->date->toDateString(),
            'purchase_number' => $purchase->purchase_number,
            'supplier' => $purchase->supplier?->name,
            'bill_number' => $purchase->bill_number,
            'payment_mode' => ucfirst((string) $purchase->payment_mode),
            'total' => $purchase->total,
            'status' => $purchase->status === 'cancelled' ? 'Cancelled' : 'Posted',
            'cancelled_on' => $purchase->cancelled_at?->toDateString(),
            'cancelled_by' => $purchase->canceller?->name,
            'cancel_reason' => $purchase->cancel_reason,
        ]);

        $total = Money::sum($purchases
            ->reject(fn (Purchase $purchase): bool => $purchase->status === 'cancelled')
            ->map(fn (Purchase $purchase): Money => Money::of($purchase->total)))->toString();

        return Excel::download(new PurchaseListExport($rows, $total), "purchases.{$extension}");
    }

    public function cancel(Request $request, Purchase $purchase): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $purchase->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException|AuthorizationException $e) {
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
        $purchase->load(['supplier', 'bankAccount', 'lines.item', 'lines.itemUnit', 'journalVoucher.fiscalYear', 'canceller:id,name']);

        $company = CompanySetting::current();

        // The number stored when the bill was posted, so a later prefix change
        // never renumbers a bill already printed (flags G-18).
        $documentNumber = $purchase->purchase_number;

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
            ...$this->branchHeader($request, $purchase->branch_id),
        ])->stream("purchase-{$purchase->id}.pdf");
    }

    /**
     * The branch line of a printed bill's header (plan 12, BR12): the
     * branch's name under the company's own word for it, only for a
     * multi-branch company. Everyone else gets nulls and the header prints
     * exactly as before.
     *
     * @return array{branchName: ?string, branchLabel: ?string}
     */
    private function branchHeader(Request $request, ?int $branchId): array
    {
        if ($branchId === null || ! ActiveBranchSelection::isMultiBranch($request)) {
            return ['branchName' => null, 'branchLabel' => null];
        }

        return [
            'branchName' => Branch::query()->whereKey($branchId)->value('name'),
            'branchLabel' => ActiveBranchSelection::label(),
        ];
    }

    /**
     * Active stores for the create form's store picker. A multi-branch
     * company also gets each store's branch_id, so the form offers only the
     * chosen branch's stores (plan 12, section 5.5), and a branch-restricted
     * user only sees stores of branches they may use. Anyone else gets the
     * same list as before.
     *
     * @return Collection<int, Store>
     */
    private function storeOptions(Request $request): Collection
    {
        // BranchStoreOptions (BR22 C5) also skips stores of a deactivated
        // branch, which nothing can post into; untouched when not
        // multi-branch, and the same columns either way.
        return BranchStoreOptions::apply(Store::where('is_active', true), $request)
            ->orderBy('name')
            ->get(BranchStoreOptions::columns($request, ['id', 'name']));
    }

    /**
     * @return array{from: ?string, to: ?string, supplier_id: ?int, search: ?string, sort: string, sort_dir: string}
     */
    private function listFilters(Request $request): array
    {
        $sort = $request->string('sort')->toString();
        $sortDir = $request->string('sort_dir')->toString();

        return [
            'from' => $request->filled('from') ? $request->string('from')->toString() : null,
            'to' => $request->filled('to') ? $request->string('to')->toString() : null,
            'supplier_id' => $request->filled('supplier_id') ? (int) $request->input('supplier_id') : null,
            // A partial match against the number printed on the supplier's
            // own bill - the one reference a clerk has in hand.
            'search' => $request->filled('search') ? trim($request->string('search')->toString()) : null,
            'sort' => array_key_exists($sort, self::SORTABLE_COLUMNS) ? $sort : 'date',
            'sort_dir' => $sortDir === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * The list's filters plus the top-bar branch switcher (BranchListFilter,
     * a no-op unless a multi-branch company is narrowed to one branch), so
     * the page, its totals row and the export always agree.
     *
     * @param  array{from: ?string, to: ?string, supplier_id: ?int, search: ?string, sort: string, sort_dir: string}  $filters
     * @return Builder<Purchase>
     */
    private function filteredPurchasesQuery(array $filters, Request $request): Builder
    {
        return BranchListFilter::apply(Purchase::query(), $request)
            ->when($filters['from'], fn ($query, string $from) => $query->whereDate('date', '>=', $from))
            ->when($filters['to'], fn ($query, string $to) => $query->whereDate('date', '<=', $to))
            ->when($filters['supplier_id'], fn ($query, int $supplierId) => $query->where('supplier_id', $supplierId))
            ->when($filters['search'], fn ($query, string $search) => $query->where('bill_number', 'like', "%{$search}%"));
    }

    /**
     * @param  array{from: ?string, to: ?string, supplier_id: ?int, search: ?string, sort: string, sort_dir: string}  $filters
     * @return array<string, string>
     */
    private function filteredTotals(array $filters, Request $request): array
    {
        // Cancelled purchases stay in the list but never move the totals row,
        // the same as the sales list (SaleController::filteredTotals()).
        $row = $this->filteredPurchasesQuery($filters, $request)->where('status', '!=', 'cancelled')->toBase()->selectRaw(
            'COALESCE(SUM(taxable_amount), 0) as taxable_amount, '
            .'COALESCE(SUM(nontaxable_amount), 0) as nontaxable_amount, '
            .'COALESCE(SUM(vat_amount), 0) as vat_amount, '
            .'COALESCE(SUM(round_off), 0) as round_off, '
            .'COALESCE(SUM(total), 0) as total'
        )->first();

        return [
            'taxable_amount' => Money::round($row->taxable_amount)->toString(),
            'nontaxable_amount' => Money::round($row->nontaxable_amount)->toString(),
            'vat_amount' => Money::round($row->vat_amount)->toString(),
            'round_off' => Money::round($row->round_off)->toString(),
            'total' => Money::round($row->total)->toString(),
        ];
    }

    /**
     * The fiscal year this bill will be posted into: the one named by the form
     * (a correction posting) or else the open year. Bill numbers are unique
     * per supplier within this year only.
     */
    private function targetFiscalYearId(Request $request): ?int
    {
        if ($request->filled('fiscal_year_id')) {
            return (int) $request->input('fiscal_year_id');
        }

        return FiscalYear::query()->where('status', FiscalYearStatus::Open)->value('id');
    }

    /**
     * Accounts filed anywhere under one head of the chart, so the bank and TDS
     * pickers offer only accounts that can legitimately be used there instead
     * of every account in the business. Never the inter-branch control
     * accounts, which AccountUnderHead refuses on submit (BranchAccounts).
     *
     * @return Collection<int, Account>
     */
    private function accountsUnderHead(string $head): Collection
    {
        return BranchAccounts::exclude(Account::query())
            ->where(fn ($query) => $query
                ->whereHas('group.accountHead', fn ($q) => $q->where('name', $head))
                ->orWhereHas('subgroup.accountGroup.accountHead', fn ($q) => $q->where('name', $head)))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }
}
