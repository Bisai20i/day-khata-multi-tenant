<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Exports\PartyWiseSalesExport;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Store;
use App\Support\Branches\BranchFilter;
use App\Support\Branches\BranchStock;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Customer-wise Sales: sales turnover per customer for a period (the legacy
 * "customer wise sales" page, which listed one customer's bills, rebuilt as a
 * summary over every customer with a per-customer drill-down).
 *
 * One row per customer with activity in the period:
 *
 * - documents / taxable / exempt / VAT / total: POSTED sales dated in the
 *   period. The Sales Register totals posted sales only, with the same date,
 *   store, payment-mode and branch filters, so the Total column sums to the
 *   register's total for the same filters by construction.
 * - returns: POSTED credit notes dated in the period (their stored
 *   `credit_note_number`, `total` and `tds_amount`), attributed to the
 *   original sale's customer, or to the note's own customer when unlinked.
 * - net: total less returns. TDS: TDS withheld on the sales less the TDS the
 *   credit notes reversed.
 * - received: what was settled at billing on the period's sales (cash, bank
 *   and partial sales settle `total - tds` in full, C7) plus every posted
 *   receipt from the customer dated in the period. A receipt carries no store
 *   and no invoice payment mode, so with the store or payment-mode filter on,
 *   only the receipt allocations to invoices that match the filters are
 *   counted (an unallocated advance has no store and drops out); otherwise
 *   the whole receipt amount counts and only the branch filter narrows it.
 * - outstanding: Sale::outstandingAmount() summed over the customer's posted
 *   sales dated up to the end date that match the filters. That method reads
 *   the documents' current state, so a receipt or return dated after the end
 *   date is already reflected (the same basis as Aged Receivables). Opening
 *   ledger balances that no invoice carries are not included: the Debtors
 *   list is the ledger-balance report.
 *
 * Cash / walk-in: sales.customer_id is never null; a sale nobody named a
 * buyer for is posted to the protected walk-in customer (Customer::walkIn()),
 * whose row is labelled "Cash / walk-in" so it is never dropped. An unlinked
 * credit note whose customer was removed lands in a "No customer recorded"
 * row instead of vanishing.
 *
 * The drill-down (`customer_id` set) is this same report narrowed to one
 * customer, so its document, credit-note and receipt totals equal the parent
 * row by construction. Screen and Excel export share report().
 */
class PartyWiseSalesReportController extends Controller
{
    /** Row key for a credit note with no customer at all. */
    private const NO_PARTY = 0;

    /** @var list<string> */
    private const PAYMENT_MODES = ['cash', 'bank', 'partial', 'credit'];

    /** Summed per party row; every one is a Money string. */
    private const ROW_AMOUNTS = [
        'taxable_amount', 'nontaxable_amount', 'vat_amount', 'total',
        'returns_total', 'net_total', 'tds_amount', 'received', 'outstanding',
    ];

    /** Summed over the drill-down's documents. */
    private const DOCUMENT_AMOUNTS = [
        'taxable_amount', 'nontaxable_amount', 'vat_amount', 'total', 'tds_amount', 'received_at_billing', 'outstanding',
    ];

    /** whereIn() batch size, comfortably inside SQLite's bound-parameter limit. */
    private const CHUNK = 500;

    public function index(Request $request): Response
    {
        $branchFilter = BranchFilter::fromRequest($request);
        $report = $this->report($request, $branchFilter);

        return Inertia::render('Tenant/Reports/PartyWiseSales', [
            ...$report,
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'stores' => BranchStock::storeOptions($branchFilter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'branchReport' => $branchFilter->payload(),
        ]);
    }

    /**
     * The same rows and totals as index() as a real .xlsx: the customer
     * summary, or the selected customer's documents when `customer_id` is set.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $branchFilter = BranchFilter::fromRequest($request);
        $report = $this->report($request, $branchFilter);

        $suffix = $report['customerId'] ? "-customer-{$report['customerId']}" : '';

        return Excel::download(
            new PartyWiseSalesExport($report, $branchFilter->isActive() ? $branchFilter->label() : null),
            "customer-wise-sales-{$report['from']}-to-{$report['to']}{$suffix}.xlsx",
        );
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>, detail: array<string, mixed>|null, from: string, to: string, customerId: int|null, storeId: int|null, paymentMode: string|null, search: string}
     */
    private function report(Request $request, BranchFilter $branchFilter): array
    {
        $filters = $this->filters($request, $branchFilter);
        $from = $filters['from'];
        $to = $filters['to'];

        $sales = $this->salesQuery($filters, $branchFilter)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->orderBy('date')
            ->orderBy('id')
            ->get([
                'id', 'customer_id', 'date', 'invoice_number', 'buyer_name', 'payment_mode', 'taxable_amount',
                'nontaxable_amount', 'vat_amount', 'total', 'tds_amount', 'branch_id',
            ]);

        [$returns, $returnSales] = $this->periodReturns($filters, $branchFilter);

        $receipts = $this->periodReceipts($filters, $branchFilter);

        $returnKey = fn (SalesReturn $return): int => $this->returnPartyKey($return, $returnSales);

        $activePartyIds = $sales->pluck('customer_id')
            ->concat($returns->map($returnKey))
            ->concat($receipts->pluck('customer_id'))
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === self::NO_PARTY)
            ->unique()
            ->values();

        $outstandingBySale = $this->outstandingBySale($filters, $branchFilter, $activePartyIds);

        $accumulators = [];
        $entry = function (int $key) use (&$accumulators): void {
            $accumulators[$key] ??= [
                'documents' => 0,
                'returns_count' => 0,
                ...array_fill_keys(['taxable_amount', 'nontaxable_amount', 'vat_amount', 'total', 'returns_total', 'tds_amount', 'received', 'outstanding'], Money::zero()),
            ];
        };

        foreach ($sales as $sale) {
            $key = (int) $sale->customer_id;
            $entry($key);
            $row = &$accumulators[$key];
            $row['documents']++;
            $row['taxable_amount'] = $row['taxable_amount']->plus(Money::of($sale->taxable_amount));
            $row['nontaxable_amount'] = $row['nontaxable_amount']->plus(Money::of($sale->nontaxable_amount));
            $row['vat_amount'] = $row['vat_amount']->plus(Money::of($sale->vat_amount));
            $row['total'] = $row['total']->plus(Money::of($sale->total));
            $row['tds_amount'] = $row['tds_amount']->plus(Money::of($sale->tds_amount ?? '0'));
            $row['received'] = $row['received']->plus($this->settledAtBilling($sale));
            unset($row);
        }

        foreach ($returns as $return) {
            $key = $returnKey($return);
            $entry($key);
            $accumulators[$key]['returns_count']++;
            $accumulators[$key]['returns_total'] = $accumulators[$key]['returns_total']->plus(Money::of($return->total));
            $accumulators[$key]['tds_amount'] = $accumulators[$key]['tds_amount']->minus(Money::of($return->tds_amount ?? '0'));
        }

        foreach ($receipts as $receipt) {
            $key = (int) $receipt->customer_id;
            $entry($key);
            $accumulators[$key]['received'] = $accumulators[$key]['received']->plus(Money::of($receipt->amount));
        }

        foreach ($outstandingBySale as $saleOutstanding) {
            $key = $saleOutstanding['customer_id'];

            if (isset($accumulators[$key])) {
                $accumulators[$key]['outstanding'] = $accumulators[$key]['outstanding']->plus($saleOutstanding['amount']);
            }
        }

        $parties = $this->parties(array_keys($accumulators));
        $rows = collect($accumulators)
            ->map(fn (array $row, int $key) => $this->partyRow($key, $row, $parties->get($key)))
            ->sort(fn (array $a, array $b) => [$a['sort_group'], mb_strtolower($a['party'])] <=> [$b['sort_group'], mb_strtolower($b['party'])])
            ->values()
            ->map(function (array $row, int $index) {
                unset($row['sort_group']);

                return ['sn' => $index + 1, ...$row];
            });

        $detail = $filters['customerId']
            ? $this->detail($filters['customerId'], $rows, $sales, $returns, $returnSales, $receipts, $outstandingBySale, $parties, $branchFilter)
            : null;

        return [
            'rows' => $rows->all(),
            'totals' => $this->totalsFor($rows, self::ROW_AMOUNTS, ['documents', 'returns_count']),
            'detail' => $detail,
            'from' => $from,
            'to' => $to,
            'customerId' => $filters['customerId'],
            'storeId' => $filters['storeId'],
            'paymentMode' => $filters['paymentMode'],
            'search' => $filters['search'],
        ];
    }

    /**
     * Strict filters: a malformed date, a reversed range or an unknown id is a
     * 422 the page shows, never a silent fallback.
     *
     * @return array{from: string, to: string, customerId: int|null, storeId: int|null, paymentMode: string|null, search: string}
     */
    private function filters(Request $request, BranchFilter $branchFilter): array
    {
        $request->validate([
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'payment_mode' => ['nullable', 'string', 'in:'.implode(',', self::PAYMENT_MODES)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        [$from, $to] = $this->resolveDateRange($request);
        $paymentMode = $request->string('payment_mode')->toString();

        return [
            'from' => $from,
            'to' => $to,
            'customerId' => $request->integer('customer_id') ?: null,
            'storeId' => $branchFilter->allowedStoreId($request->integer('store_id') ?: null),
            'paymentMode' => $paymentMode !== '' ? $paymentMode : null,
            'search' => $request->string('search')->trim()->toString(),
        ];
    }

    /**
     * The posted receipts dated in the period, one object per row with `id`
     * (the receipt), `customer_id` and `amount`. Without a store or
     * payment-mode filter that is every receipt of the in-scope customers.
     * With either filter it is the receipt ALLOCATIONS to posted invoices
     * matching the filters (any invoice date; the customer is the invoice's),
     * so the figure reconciles with the filtered documents; an allocation
     * row is one receipt's share to one invoice, so a receipt can appear more
     * than once and is counted by `id`.
     *
     * @param  array{from: string, to: string, customerId: int|null, storeId: int|null, paymentMode: string|null, search: string}  $filters
     * @return Collection<int, object>
     */
    private function periodReceipts(array $filters, BranchFilter $branchFilter): Collection
    {
        if ($filters['storeId'] === null && $filters['paymentMode'] === null) {
            return Receipt::query()
                ->where('status', 'posted')
                ->whereDate('date', '>=', $filters['from'])
                ->whereDate('date', '<=', $filters['to'])
                ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'receipts.branch_id'))
                ->when($this->partyScope($filters), fn (Builder $query, Builder $scope) => $query->whereIn('customer_id', $scope))
                ->get(['id', 'customer_id', 'amount'])
                ->toBase();
        }

        return DB::table('receipt_allocations')
            ->join('receipts', 'receipts.id', '=', 'receipt_allocations.receipt_id')
            ->join('sales', 'sales.id', '=', 'receipt_allocations.sale_id')
            ->where('receipts.status', 'posted')
            ->whereDate('receipts.date', '>=', $filters['from'])
            ->whereDate('receipts.date', '<=', $filters['to'])
            ->tap(fn ($query) => $branchFilter->applyToDocuments($query, 'receipts.branch_id'))
            ->whereIn('receipt_allocations.sale_id', $this->salesQuery($filters, $branchFilter)->select('sales.id'))
            ->get(['receipts.id as id', 'sales.customer_id as customer_id', 'receipt_allocations.amount as amount']);
    }

    /**
     * Posted sales matching every filter except the date, shared by the
     * period rows and the outstanding figure so the two always describe the
     * same documents.
     *
     * @param  array{customerId: int|null, storeId: int|null, paymentMode: string|null, search: string}  $filters
     * @return Builder<Sale>
     */
    private function salesQuery(array $filters, BranchFilter $branchFilter): Builder
    {
        return Sale::query()
            ->where('status', 'posted')
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'sales.branch_id'))
            ->when($filters['storeId'], fn (Builder $query, int $storeId) => $query->where('store_id', $storeId))
            ->when($filters['paymentMode'], fn (Builder $query, string $mode) => $query->where('payment_mode', $mode))
            ->when($this->partyScope($filters), fn (Builder $query, Builder $scope) => $query->whereIn('customer_id', $scope));
    }

    /**
     * The customers the customer and search filters allow, as a subquery
     * (search matches the customer's name or PAN), or null for everyone.
     *
     * @param  array{customerId: int|null, search: string}  $filters
     * @return Builder<Customer>|null
     */
    private function partyScope(array $filters): ?Builder
    {
        if ($filters['customerId'] === null && $filters['search'] === '') {
            return null;
        }

        $search = $filters['search'];
        $pattern = '%'.addcslashes($search, '\\%_').'%';

        return Customer::query()
            ->select('id')
            ->when($filters['customerId'], fn (Builder $query, int $id) => $query->whereKey($id))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($pattern) {
                $inner->where('name', 'like', $pattern)->orWhere('tpin', 'like', $pattern);
            }));
    }

    /**
     * Posted credit notes dated in the period, plus the customer and invoice
     * number of each linked note's sale. The sale is read with the query
     * builder (no branch access scope), exactly as Sale::outstandingAmount()
     * reads returns, so the party a note belongs to never depends on who is
     * looking. A payment-mode filter keeps only notes against sales of that
     * mode (an unlinked note has no sale, so it drops out).
     *
     * @param  array{from: string, to: string, customerId: int|null, storeId: int|null, paymentMode: string|null, search: string}  $filters
     * @return array{0: Collection<int, SalesReturn>, 1: Collection<int, object>}
     */
    private function periodReturns(array $filters, BranchFilter $branchFilter): array
    {
        $scope = $this->partyScope($filters);

        $returns = SalesReturn::query()
            ->where('status', 'posted')
            ->whereDate('date', '>=', $filters['from'])
            ->whereDate('date', '<=', $filters['to'])
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'sales_returns.branch_id'))
            ->when($filters['storeId'], fn (Builder $query, int $storeId) => $query->where('store_id', $storeId))
            ->when($filters['paymentMode'], fn (Builder $query, string $mode) => $query->whereIn(
                'sale_id',
                DB::table('sales')->select('id')->where('payment_mode', $mode),
            ))
            ->when($scope, fn (Builder $query) => $query->where(function (Builder $inner) use ($scope) {
                $inner->whereIn('sale_id', DB::table('sales')->select('id')->whereIn('customer_id', $scope))
                    ->orWhere(fn (Builder $unlinked) => $unlinked->whereNull('sale_id')->whereIn('customer_id', $scope));
            }))
            ->orderBy('date')
            ->orderBy('id')
            ->get(['id', 'sale_id', 'customer_id', 'credit_note_number', 'date', 'total', 'tds_amount', 'branch_id']);

        $returnSales = collect();

        foreach ($returns->pluck('sale_id')->filter()->unique()->chunk(self::CHUNK) as $saleIds) {
            $returnSales = $returnSales->union(
                DB::table('sales')->whereIn('id', $saleIds->values()->all())->get(['id', 'customer_id', 'invoice_number'])->keyBy('id'),
            );
        }

        return [$returns, $returnSales];
    }

    /**
     * The customer a credit note belongs to: its sale's customer when linked,
     * its own customer when unlinked, NO_PARTY when neither is known.
     *
     * @param  Collection<int, object>  $returnSales
     */
    private function returnPartyKey(SalesReturn $return, Collection $returnSales): int
    {
        $saleCustomerId = $return->sale_id !== null ? $returnSales->get($return->sale_id)?->customer_id : null;

        return (int) ($saleCustomerId ?? $return->customer_id ?? self::NO_PARTY);
    }

    /**
     * Sale::outstandingAmount() for every posted sale of the active customers
     * dated up to the end date and matching the filters, keyed by sale id,
     * computed through its batch form Sale::outstandingAmounts() (same rule,
     * two queries per batch of CHUNK sales).
     *
     * Only sales whose answer can be non-zero are sent. For a cash, bank or
     * partial sale it is
     * `settlement_due - settlement_due - returns + refunds - allocations`;
     * with no posted return and no live receipt allocation every term after
     * the first two is zero, so such a sale is exactly 0.00 and is skipped.
     * Credit sales, and any other sale with a posted return or a live
     * allocation, go through the batch method. Both probes read outside the
     * branch scope, as the method does.
     *
     * @param  array{to: string, customerId: int|null, storeId: int|null, paymentMode: string|null, search: string}  $filters
     * @param  Collection<int, int>  $partyIds
     * @return array<int, array{customer_id: int, amount: Money}>
     */
    private function outstandingBySale(array $filters, BranchFilter $branchFilter, Collection $partyIds): array
    {
        $outstanding = [];

        foreach ($partyIds->chunk(self::CHUNK) as $chunk) {
            $sales = $this->salesQuery($filters, $branchFilter)
                ->whereDate('date', '<=', $filters['to'])
                ->whereIn('customer_id', $chunk->values()->all())
                ->get(['id', 'customer_id', 'payment_mode', 'total', 'tds_amount']);

            $settledIds = $sales->where('payment_mode', '!=', 'credit')->modelKeys();
            $touched = [];

            foreach (array_chunk($settledIds, self::CHUNK) as $ids) {
                $withReturns = DB::table('sales_returns')->whereIn('sale_id', $ids)->where('status', 'posted')->distinct()->pluck('sale_id');
                $withAllocations = DB::table('receipt_allocations')
                    ->join('receipts', 'receipts.id', '=', 'receipt_allocations.receipt_id')
                    ->whereIn('receipt_allocations.sale_id', $ids)
                    ->where('receipts.status', '!=', 'cancelled')
                    ->distinct()
                    ->pluck('receipt_allocations.sale_id');

                foreach ($withReturns->concat($withAllocations) as $saleId) {
                    $touched[(int) $saleId] = true;
                }
            }

            $candidates = $sales->filter(fn (Sale $sale) => $sale->payment_mode === 'credit' || isset($touched[$sale->id]));
            $amounts = [];

            foreach ($candidates->chunk(self::CHUNK) as $batch) {
                $amounts += Sale::outstandingAmounts($batch);
            }

            foreach ($sales as $sale) {
                $outstanding[$sale->id] = [
                    'customer_id' => (int) $sale->customer_id,
                    'amount' => $amounts[$sale->id] ?? Money::zero(),
                ];
            }
        }

        return $outstanding;
    }

    /**
     * What was settled when the bill was posted: the whole amount due after
     * TDS for a cash, bank or partial sale, nothing for a credit sale - the
     * same term Sale::outstandingAmount() subtracts.
     */
    private function settledAtBilling(Sale $sale): Money
    {
        if ($sale->payment_mode === 'credit') {
            return Money::zero();
        }

        return Money::of($sale->total)->minus(Money::of($sale->tds_amount ?? '0'));
    }

    /**
     * @param  list<int>  $keys
     * @return Collection<int, Customer>
     */
    private function parties(array $keys): Collection
    {
        $ids = array_values(array_filter($keys, fn (int $key) => $key !== self::NO_PARTY));
        $parties = collect();

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $parties = $parties->union(Customer::query()->whereIn('id', $chunk)->get(['id', 'name', 'tpin', 'is_walk_in'])->keyBy('id'));
        }

        return $parties;
    }

    /**
     * @param  array<string, mixed>  $row  Money accumulators
     * @return array<string, mixed>
     */
    private function partyRow(int $key, array $row, ?Customer $customer): array
    {
        $isWalkIn = (bool) $customer?->is_walk_in;
        $label = match (true) {
            $key === self::NO_PARTY => 'No customer recorded',
            $isWalkIn => 'Cash / walk-in',
            default => $customer?->name ?? "Customer #{$key}",
        };

        $amounts = [];

        foreach (['taxable_amount', 'nontaxable_amount', 'vat_amount', 'total', 'returns_total', 'tds_amount', 'received', 'outstanding'] as $amountKey) {
            $amounts[$amountKey] = $row[$amountKey]->toString();
        }

        return [
            'party_id' => $key === self::NO_PARTY ? null : $key,
            'party' => $label,
            'pan' => $customer?->tpin,
            'is_walk_in' => $isWalkIn,
            'documents' => $row['documents'],
            'returns_count' => $row['returns_count'],
            ...$amounts,
            'net_total' => $row['total']->minus($row['returns_total'])->toString(),
            'sort_group' => $key === self::NO_PARTY ? 2 : ($isWalkIn ? 1 : 0),
        ];
    }

    /**
     * The selected customer's documents. Every list is the parent report's
     * own collection narrowed to this customer, so each total equals the
     * matching cell of the customer's row; the outstanding on invoices dated
     * before the period is the row's outstanding less this list's.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<int, Sale>  $sales
     * @param  Collection<int, SalesReturn>  $returns
     * @param  Collection<int, object>  $returnSales
     * @param  Collection<int, object>  $receipts
     * @param  array<int, array{customer_id: int, amount: Money}>  $outstandingBySale
     * @param  Collection<int, Customer>  $parties
     * @return array<string, mixed>
     */
    private function detail(
        int $customerId,
        Collection $rows,
        Collection $sales,
        Collection $returns,
        Collection $returnSales,
        Collection $receipts,
        array $outstandingBySale,
        Collection $parties,
        BranchFilter $branchFilter,
    ): array {
        $row = $rows->firstWhere('party_id', $customerId);
        $customer = $parties->get($customerId) ?? Customer::query()->find($customerId, ['id', 'name', 'tpin', 'is_walk_in']);

        $documents = $sales
            ->filter(fn (Sale $sale) => (int) $sale->customer_id === $customerId)
            ->values()
            ->map(function (Sale $sale, int $index) use ($outstandingBySale, $branchFilter) {
                $outstanding = $outstandingBySale[$sale->id]['amount'] ?? Money::zero();
                $due = Money::of($sale->total)->minus(Money::of($sale->tds_amount ?? '0'));

                return [
                    'sn' => $index + 1,
                    'id' => $sale->id,
                    'date' => $sale->date->toDateString(),
                    'number' => $sale->invoice_number,
                    'buyer_name' => $sale->buyer_name,
                    'payment_mode' => $sale->payment_mode,
                    'taxable_amount' => $sale->taxable_amount,
                    'nontaxable_amount' => $sale->nontaxable_amount,
                    'vat_amount' => $sale->vat_amount,
                    'total' => $sale->total,
                    'tds_amount' => Money::of($sale->tds_amount ?? '0')->toString(),
                    'received_at_billing' => $this->settledAtBilling($sale)->toString(),
                    'outstanding' => $outstanding->toString(),
                    'payment_status' => $this->paymentStatus($outstanding, $due),
                    ...$branchFilter->rowBranch($sale->branch_id),
                ];
            });

        $partyReturns = $returns
            ->filter(fn (SalesReturn $return) => $this->returnPartyKey($return, $returnSales) === $customerId)
            ->values()
            ->map(fn (SalesReturn $return, int $index) => [
                'sn' => $index + 1,
                'id' => $return->id,
                'date' => $return->date->toDateString(),
                'number' => $return->credit_note_number,
                'against' => $returnSales->get($return->sale_id)?->invoice_number,
                'total' => $return->total,
                'tds_amount' => Money::of($return->tds_amount ?? '0')->toString(),
                ...$branchFilter->rowBranch($return->branch_id),
            ]);

        $partyReceipts = $receipts->filter(fn (object $receipt) => (int) $receipt->customer_id === $customerId);
        $documentTotals = $this->totalsFor($documents, self::DOCUMENT_AMOUNTS);
        $rowOutstanding = Money::of($row['outstanding'] ?? '0');

        return [
            'party' => [
                'id' => $customerId,
                'name' => $customer?->is_walk_in ? 'Cash / walk-in' : ($customer?->name ?? "Customer #{$customerId}"),
                'pan' => $customer?->tpin,
                'is_walk_in' => (bool) $customer?->is_walk_in,
            ],
            'documents' => $documents->all(),
            'documentTotals' => $documentTotals,
            'returns' => $partyReturns->all(),
            'returnTotals' => $this->totalsFor($partyReturns, ['total', 'tds_amount']),
            'receipts' => [
                'count' => $partyReceipts->pluck('id')->unique()->count(),
                'amount' => Money::sum($partyReceipts->map(fn (object $receipt) => Money::of($receipt->amount)))->toString(),
            ],
            'earlierOutstanding' => $rowOutstanding->minus(Money::of($documentTotals['outstanding']))->toString(),
        ];
    }

    /**
     * @return 'paid'|'partial'|'unpaid'|'credit'
     */
    private function paymentStatus(Money $outstanding, Money $due): string
    {
        return match (true) {
            $outstanding->isNegative() => 'credit',
            $outstanding->isZero() => 'paid',
            $outstanding->isGreaterThanOrEqualTo($due) => 'unpaid',
            default => 'partial',
        };
    }

    /**
     * Totals summed in Money over exactly the rows returned, so a footer can
     * never disagree with the list above it.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<string>  $moneyKeys
     * @param  list<string>  $countKeys
     * @return array<string, string|int>
     */
    private function totalsFor(Collection $rows, array $moneyKeys, array $countKeys = []): array
    {
        $totals = [];

        foreach ($countKeys as $key) {
            $totals[$key] = (int) $rows->sum($key);
        }

        foreach ($moneyKeys as $key) {
            $totals[$key] = Money::sum($rows->map(fn (array $row) => Money::of($row[$key] ?? '0')))->toString();
        }

        return $totals;
    }

    /**
     * Defaults to the open fiscal year, falling back to month-to-date.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(Request $request): array
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        if ($from !== '' && $to !== '') {
            return [$from, $to];
        }

        $fiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

        if ($fiscalYear) {
            return [$fiscalYear->start_date->toDateString(), $fiscalYear->end_date->toDateString()];
        }

        return [now()->startOfMonth()->toDateString(), now()->toDateString()];
    }
}
