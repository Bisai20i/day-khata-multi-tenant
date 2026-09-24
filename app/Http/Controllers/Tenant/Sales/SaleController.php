<?php

namespace App\Http\Controllers\Tenant\Sales;

use App\Exports\SalesExport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Agent;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Item;
use App\Models\PrintLog;
use App\Models\Sale;
use App\Models\SaleNoteTemplate;
use App\Models\Store;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;

class SaleController extends Controller
{
    /**
     * Party ledgers (customers, suppliers, agents) live under these
     * subgroups. They are perfectly valid accounts, but never a bank, cash or
     * TDS account, so the settlement pickers exclude them - see
     * settlementAccounts().
     */
    private const PARTY_SUBGROUPS = ['Sundry Debtors', 'Sundry Creditors', 'Sales Agents'];

    /**
     * Sortable columns for the list (audit section 4 polish, "list ...
     * sorting"), whitelisted rather than taking `sort` straight off the
     * request - the same reasoning as `storeRules()`'s `Rule::in()` pickers
     * below: a raw column name from the browser must never reach `orderBy()`.
     *
     * @var array<string, string>
     */
    /**
     * How many copies one "Save & Print N copies" job may produce. A cap
     * exists because every copy is a rendered PDF page AND a print-log row:
     * an accidental 500 would both hang the request and pollute the print
     * log, and no counter in a shop needs more than a handful of sheets.
     */
    private const MAX_PRINT_COPIES = 5;

    private const SORTABLE_COLUMNS = [
        'date' => 'date',
        'invoice_number' => 'invoice_number',
        'total' => 'total',
    ];

    /**
     * Listing is server-side filtered (date range + customer + invoice
     * number search), sorted and paginated - same `when()`/
     * `paginate()->withQueryString()` shape Central\Tenants\TenantController
     * ::index() established, so it stays consistent across the app rather
     * than loading every sale unfiltered (a real usability problem once
     * invoice history grows).
     */
    public function index(Request $request): Response
    {
        $filters = $this->listFilters($request);
        $settings = CompanySetting::current();

        $sales = $this->filteredSalesQuery($filters)
            ->with(['customer:id,name', 'agent:id,name', 'lines.item:id,name,unit'])
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Tenant/Sales/Index', [
            'sales' => $sales,
            'filters' => $filters,
            // Exact SQL sums over the same filtered/searched set the page
            // lists (never a page's worth of client-side addition), so the
            // totals row always ties to what is actually on screen (audit
            // section 4 polish, "list ... totals row").
            'totals' => $this->filteredTotals($filters),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'mobile_no']),
            'items' => $this->itemsForPicker(),
            'bankAccounts' => $this->settlementAccounts(),
            'tdsAccounts' => $this->settlementAccounts(),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'agents' => Agent::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'commission_rate']),
            'noteTemplates' => SaleNoteTemplate::query()->orderBy('text')->get(['id', 'text']),
            'walkInCustomerId' => Customer::walkIn()?->id,
            'invoiceSettings' => [
                'default_vat_rate' => $settings->default_vat_rate,
                'default_store_id' => $settings->default_store_id,
                'active_invoice_type' => $settings->active_invoice_type ?? 'full',
            ],
        ]);
    }

    /**
     * @return array{from: ?string, to: ?string, customer_id: ?int, search: ?string, sort: string, sort_dir: string}
     */
    private function listFilters(Request $request): array
    {
        $sort = $request->string('sort')->toString();
        $sortDir = $request->string('sort_dir')->toString();

        return [
            'from' => $request->filled('from') ? $request->string('from')->toString() : null,
            'to' => $request->filled('to') ? $request->string('to')->toString() : null,
            'customer_id' => $request->filled('customer_id') ? (int) $request->input('customer_id') : null,
            // Invoice number search (audit section 4 polish, "list ...
            // search by invoice number") - a partial, case-insensitive match
            // against the stored number (C7), never a re-derived one.
            'search' => $request->filled('search') ? trim($request->string('search')->toString()) : null,
            'sort' => array_key_exists($sort, self::SORTABLE_COLUMNS) ? $sort : 'date',
            'sort_dir' => $sortDir === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * @param  array{from: ?string, to: ?string, customer_id: ?int, search: ?string, sort: string, sort_dir: string}  $filters
     * @return Builder<Sale>
     */
    private function filteredSalesQuery(array $filters): Builder
    {
        $sortColumn = self::SORTABLE_COLUMNS[$filters['sort']];

        return Sale::query()
            ->when($filters['from'], fn ($query, string $from) => $query->whereDate('date', '>=', $from))
            ->when($filters['to'], fn ($query, string $to) => $query->whereDate('date', '<=', $to))
            ->when($filters['customer_id'], fn ($query, int $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['search'], fn ($query, string $search) => $query->where('invoice_number', 'like', "%{$search}%"))
            ->orderBy($sortColumn, $filters['sort_dir'])
            ->orderByDesc('id');
    }

    /**
     * @param  array{from: ?string, to: ?string, customer_id: ?int, search: ?string, sort: string, sort_dir: string}  $filters
     * @return array<string, string>
     */
    private function filteredTotals(array $filters): array
    {
        // Cancelled invoices stay in the list but never move the totals row.
        $row = $this->filteredSalesQuery($filters)->where('status', '!=', 'cancelled')->toBase()->selectRaw(
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
     * Export of the same filtered/sorted/searched set index() shows (audit
     * section 4 polish, "list export"), every row and never a paginated
     * page's worth. `format=csv` streams a .csv instead of the default
     * .xlsx - Laravel Excel infers the writer from the filename extension.
     */
    public function export(Request $request)
    {
        $filters = $this->listFilters($request);
        $extension = $request->query('format') === 'csv' ? 'csv' : 'xlsx';

        $rows = $this->filteredSalesQuery($filters)
            ->with(['customer:id,name', 'agent:id,name'])
            ->get()
            ->map(fn (Sale $sale): array => [
                'date' => $sale->date->format('Y-m-d'),
                'invoice_number' => $sale->invoice_number,
                'invoice_type' => ucfirst($sale->invoice_type),
                'customer' => $sale->customer?->name,
                'agent' => $sale->agent?->name,
                'payment_mode' => ucfirst($sale->payment_mode),
                'status' => ucfirst($sale->status),
                'taxable_amount' => $sale->taxable_amount,
                'nontaxable_amount' => $sale->nontaxable_amount,
                'vat_amount' => $sale->vat_amount,
                'total' => $sale->total,
            ]);

        return Excel::download(new SalesExport($rows, $this->filteredTotals($filters)), "sales.{$extension}");
    }

    /**
     * Saves a note template (audit section 4 polish, "note templates and
     * per-line notes") - a tiny, admin-free CRUD any tenant user may grow,
     * matching how items/customers/etc. are already managed in this app.
     */
    public function storeNoteTemplate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:500'],
        ]);

        SaleNoteTemplate::create($data);

        return back()->with('status', 'Note template saved.');
    }

    public function destroyNoteTemplate(SaleNoteTemplate $saleNoteTemplate): RedirectResponse
    {
        $saleNoteTemplate->delete();

        return back()->with('status', 'Note template deleted.');
    }

    /**
     * The item picker's payload. `sale_rate` is included so Create.vue can
     * prefill a line's rate the way the POS tiles already do (audit P2
     * "rate autofill: sale_rate not sent to Create"), and `current_stock` is
     * an exact 4dp Quantity string rather than a float, so the browser can
     * render it with formatQuantity() without ever doing decimal arithmetic.
     *
     * Stock here is the cross-store total, deliberately identical to
     * PosController's tiles (Sale::post() still enforces the per-store check
     * at posting time) so the same item never shows two different numbers on
     * two screens.
     *
     * @return Collection<int, Item>
     */
    private function itemsForPicker(): Collection
    {
        $items = Item::query()->where('is_active', true)->orderBy('name')
            ->with(['units' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
            ->get(['id', 'name', 'unit', 'is_vatable', 'is_stockable', 'barcode', 'sale_rate', 'hs_code', 'min_stock']);

        $stock = Item::currentStockByItem($items->pluck('id')->all());

        return $items->map(function (Item $item) use ($stock) {
            $item->current_stock = $item->is_stockable
                ? ($stock[$item->id] ?? null)?->toString()
                : null;

            return $item;
        });
    }

    /**
     * Accounts that may hold a settlement: cash, bank and TDS ledgers.
     *
     * The chart of accounts has no dedicated "Bank" or "TDS" group, so
     * eligibility is defined by what an account cannot be: a Profit & Loss
     * account (income or expense) never holds money, and a party ledger
     * (customer, supplier, agent) is the other side of the transaction, not
     * the account it settles into. Picking one of those was the audit's
     * "bank, TDS and refund pickers filtered by account type" gap; the same
     * rule is applied again server-side in store(), since a filtered picker
     * is a convenience, not a control.
     *
     * @return Collection<int, Account>
     */
    private function settlementAccounts(): Collection
    {
        return $this->settlementAccountQuery()->orderBy('name')->get(['id', 'code', 'name']);
    }

    /**
     * @return Builder<Account>
     */
    private function settlementAccountQuery(): Builder
    {
        return Account::query()
            ->where(function (Builder $query) {
                $query->whereHas('group.accountHead', fn (Builder $head) => $head->where('is_profit_and_loss', false))
                    ->orWhereHas('subgroup.accountGroup.accountHead', fn (Builder $head) => $head->where('is_profit_and_loss', false));
            })
            ->whereDoesntHave('subgroup', fn (Builder $subgroup) => $subgroup->whereIn('name', self::PARTY_SUBGROUPS));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->storeRules());

        try {
            $sale = Sale::post(
                Arr::except($data, ['lines']),
                $data['lines'],
                $request->user(),
            );
        } catch (BillingException $e) {
            // The browser previewed one total and the server computed another,
            // so the bill on screen is not the bill being saved (C8). Surfaced
            // on the `expected_total` field rather than as a generic line
            // error, so the form can point at the totals panel.
            if ($e->reason === BillingException::REASON_TOTAL_MISMATCH) {
                throw ValidationException::withMessages([
                    'expected_total' => 'The bill total changed. Please review it before saving.',
                ]);
            }

            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        } catch (AuthorizationException $e) {
            // The fiscal-year guard now lives inside JournalVoucher::write()
            // (C4), and it raises an AuthorizationException - not an
            // InvalidArgumentException - when someone who is not an admin
            // posts into a year that was reopened for correction. Shown on the
            // date field, since the date is what put the bill in that year.
            return back()->withErrors(['date' => $e->getMessage()])->withInput();
        }

        // C11: the posted document identifies itself in the flash, so the page
        // can open its print view directly instead of guessing the newest id
        // out of the list it was redirected to (audit P0-6, which made every
        // POS sale throw and left the posted cart on screen).
        return redirect()->route('tenant.sales.index')
            ->with('status', 'Sale posted.')
            ->with('created', [
                'type' => 'sale',
                'id' => $sale->id,
                'print_url' => route('tenant.sales.print', $sale),
                'receipt' => $this->receiptPayload($sale),
            ]);
    }

    /**
     * The stored sale, shaped for the POS "Sale complete" confirmation.
     *
     * C8 requires a posted document to be rendered from stored server values.
     * The POS used to show a snapshot of what the cart looked like just before
     * submitting, which could differ from the bill that was actually saved -
     * and did, by a paisa, on every bill the old float preview got wrong
     * (P0-8). The change owed is the only figure the browser still works out
     * itself, because cash tendered at the counter is not part of the bill.
     *
     * @return array<string, mixed>
     */
    private function receiptPayload(Sale $sale): array
    {
        $sale->loadMissing(['lines.item:id,name,unit', 'lines.itemUnit:id,name']);

        [$cashSettled, $bankSettled] = match ($sale->payment_mode) {
            'cash' => [Money::of($sale->total)->minus(Money::of($sale->tds_amount)), Money::zero()],
            'bank' => [Money::zero(), Money::of($sale->total)->minus(Money::of($sale->tds_amount))],
            'partial' => [Money::of($sale->cash_amount ?? '0'), Money::of($sale->bank_amount ?? '0')],
            default => [Money::zero(), Money::zero()],
        };

        return [
            'invoice_number' => $sale->invoice_number,
            'date' => $sale->date->format('Y-m-d'),
            'date_bs' => NepaliCalendar::formatBs($sale->date),
            'customer_name' => $sale->buyer_name,
            'payment_mode' => $sale->payment_mode,
            'lines' => $sale->lines->map(fn ($line): array => [
                'name' => $line->item->name,
                'unit' => $line->itemUnit?->name ?? $line->item->unit,
                'quantity' => $line->quantity,
                'rate' => $line->rate,
                'line_total' => $line->line_total,
            ])->all(),
            'taxable_amount' => $sale->taxable_amount,
            'nontaxable_amount' => $sale->nontaxable_amount,
            'vat_amount' => $sale->vat_amount,
            'tds_amount' => $sale->tds_amount,
            'total' => $sale->total,
            'cash_settled' => $cashSettled->toString(),
            'bank_settled' => $bankSettled->toString(),
            'outstanding' => $sale->outstandingAmount()->toString(),
        ];
    }

    /**
     * Validation rules for posting a sale.
     *
     * Decimal places are capped to what the columns actually store (audit
     * P0-5): quantities, rates and conversion factors at 4, money and
     * percentages at 2. Before this, a quantity of 0.00004 at Rs 1,000,000
     * charged Rs 40 and stored a quantity of 0.0000 with no stock movement.
     *
     * `rate` is required rather than defaulted: a blank rate used to become
     * a silent zero, which posts a free line onto a real invoice.
     *
     * `vat_rate` is deliberately absent - the rate always comes from
     * CompanySetting::default_vat_rate (see Sale::post()), never from the
     * browser.
     *
     * @return array<string, mixed>
     */
    private function storeRules(): array
    {
        $settlementAccount = Rule::in($this->settlementAccountQuery()->pluck('id')->all());

        return [
            'customer_id' => ['required', 'exists:customers,id'],
            // invoice_type is deliberately absent: it is never a cashier
            // choice (see Sale::post(), which always reads
            // CompanySetting::active_invoice_type - set only by the
            // platform admin from the central panel).
            'chalani_number' => ['nullable', 'string', 'max:100'],
            'date' => ['required', 'date'],
            'payment_mode' => ['required', 'in:cash,bank,partial,credit'],
            'bank_account_id' => ['nullable', 'integer', $settlementAccount],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'discount' => ['nullable', 'decimal:0,2', 'min:0'],
            'discount_type' => ['nullable', 'in:percentage,flat'],
            'cash_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'bank_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'tds_account_id' => ['nullable', 'integer', $settlementAccount],
            'tds_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'agent_id' => ['nullable', 'exists:agents,id'],
            'commission_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'narration' => ['nullable', 'string', 'max:255'],
            'expected_total' => ['nullable', 'decimal:0,2'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            // Null/omitted means the item's own base unit - Sale::post()
            // resolves this against the item's own ItemUnit rows (and
            // rejects a unit that belongs to a different item), so no
            // cross-item ownership check is needed here.
            'lines.*.item_unit_id' => ['nullable', 'integer', 'exists:item_units,id'],
            // Negative allowed on purpose: a negative-quantity line is how
            // this app models an in-bill return/adjustment line (legacy
            // parity) - Sale::post() reduces revenue/VAT by the (negative)
            // line total and, via Item::recordStockMovement()'s fixed
            // StockMovementType::Sale direction, correctly nets the stock
            // effect back to a restock instead of a sale. Only exactly zero
            // is meaningless and rejected.
            'lines.*.quantity' => ['required', 'decimal:0,4', Rule::notIn([0, '0', '0.0', '0.00', '0.000', '0.0000'])],
            'lines.*.rate' => ['required', 'decimal:0,4', 'min:0'],
            'lines.*.discount' => ['nullable', 'decimal:0,2', 'min:0'],
            'lines.*.discount_type' => ['nullable', 'in:percentage,flat'],
            // Bonus/free quantity (audit section 3 "Sales"): moves stock,
            // never money - Sale::post() keeps it entirely out of
            // DocumentCalculator's input.
            'lines.*.bonus_quantity' => ['nullable', 'decimal:0,4', 'min:0'],
        ];
    }

    public function cancel(Request $request, Sale $sale): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $sale->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            // Both shapes the fiscal-year guard can fail with (C4): closed or
            // missing year raises InvalidArgumentException, a non-admin on a
            // reopened year raises AuthorizationException. Either way the
            // cashier gets the reason on the form, not an error page.
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.sales.index')->with('status', 'Sale cancelled.');
    }

    /**
     * Streams a printable PDF invoice inline (not a forced download), so it
     * opens in a new browser tab from a plain anchor link on the Index page.
     *
     * Everything printed comes from the stored row: the invoice number issued
     * at posting, the buyer as they were on the bill, and the totals as they
     * were computed - never re-derived from the current settings or the live
     * customer (C7, C9, audit P0-8 and the "Invoice and IRD compliance"
     * cluster). PrintLog::record() returns the copy number, so a reprint is
     * stamped as a copy rather than passing as a second original.
     *
     * "Save & Print N copies" (audit section 4 polish): `?copies=N` renders
     * the bill N times into ONE streamed document, one copy per page, and
     * records N separate print-log rows - so the first sheet of a brand new
     * bill is the Original and sheets 2..N are stamped "Copy of Original"
     * with their own numbers (C9). Recording one row per sheet, rather than
     * one row for the whole job, is what keeps the print-log report an
     * honest count of how many pieces of paper carry this invoice.
     */
    public function print(Request $request, Sale $sale): HttpResponse
    {
        $sale->load(['customer', 'agent', 'bankAccount', 'lines.item', 'lines.itemUnit', 'journalVoucher', 'fiscalYear']);

        $company = CompanySetting::current();
        $copies = min(max($request->integer('copies', 1), 1), self::MAX_PRINT_COPIES);

        // Thermal paper sizes get a lightweight narrow-column receipt layout
        // instead of the full A4/A5 letterhead invoice - dompdf has no
        // built-in 58mm/80mm paper preset, so the width is passed as an
        // explicit [x1, y1, x2, y2] point box (1mm ~ 2.83pt) with a generous
        // unbounded height for a continuous thermal roll.
        $isThermal = in_array($company->print_paper_size, ['58mm', '80mm'], true);

        $viewData = [
            'sale' => $sale,
            'company' => $company,
            'documentNumber' => $sale->invoice_number ?? "#{$sale->id}",
            'documentDate' => $sale->date->format('Y-m-d'),
            'dateAd' => $sale->date->format('Y-m-d'),
            'dateBs' => NepaliCalendar::formatBs($sale->date),
            'fiscalYearName' => $sale->fiscalYear?->name,
            'amountInWords' => AmountInWords::rupees(Money::of($sale->total)),
        ];

        $rendered = [];

        for ($copy = 0; $copy < $copies; $copy++) {
            $rendered[] = view(
                $isThermal ? 'pdf.sale-receipt' : 'pdf.sale',
                $viewData + ['copyNumber' => PrintLog::record($sale, $request->user())],
            )->render();
        }

        $pdf = Pdf::loadHTML($this->stitchedCopies($rendered));

        if ($isThermal) {
            $width = $company->print_paper_size === '58mm' ? 164 : 227;
            $pdf->setPaper([0, 0, $width, 2000]);
        }

        return $pdf->stream("sale-{$sale->id}.pdf");
    }

    /**
     * Joins several fully rendered copies of the same bill into one HTML
     * document: the first copy keeps its `<head>` (and therefore every style
     * rule and `@page` size the layout sets), and each later copy
     * contributes only its `<body>` content after a hard page break. Merging
     * the rendered HTML rather than the finished PDFs keeps this free of a
     * PDF-merging dependency, and leaves each copy's own "Copy of Original"
     * stamp exactly as its view rendered it.
     *
     * @param  list<string>  $documents
     */
    private function stitchedCopies(array $documents): string
    {
        $first = array_shift($documents) ?? '';

        if ($documents === []) {
            return $first;
        }

        $extra = '';

        foreach ($documents as $document) {
            $extra .= '<div style="page-break-before: always;"></div>'.$this->bodyContent($document);
        }

        $closingBody = strripos($first, '</body>');

        return $closingBody === false
            ? $first.$extra
            : substr($first, 0, $closingBody).$extra.substr($first, $closingBody);
    }

    /**
     * Everything between `<body ...>` and `</body>` of a rendered document,
     * or the whole document when it has no body tag at all (nothing in this
     * app renders that way today, but a fragment must still print rather
     * than vanish).
     */
    private function bodyContent(string $document): string
    {
        $openTag = stripos($document, '<body');
        $closingBody = strripos($document, '</body>');

        if ($openTag === false || $closingBody === false) {
            return $document;
        }

        $bodyStart = strpos($document, '>', $openTag);

        if ($bodyStart === false || $bodyStart > $closingBody) {
            return $document;
        }

        return substr($document, $bodyStart + 1, $closingBody - $bodyStart - 1);
    }
}
