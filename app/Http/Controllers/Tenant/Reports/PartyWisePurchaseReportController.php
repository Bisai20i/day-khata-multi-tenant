<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Exports\PartyWisePurchaseExport;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Store;
use App\Models\Supplier;
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
 * Supplier-wise Purchase: purchase turnover per supplier for a period, the
 * purchase-side mirror of PartyWiseSalesReportController (legacy's
 * "supplier wise purchase" page was an unfinished stub).
 *
 * One row per supplier with activity in the period:
 *
 * - documents / taxable / exempt / VAT / total: POSTED trading purchases
 *   (the Purchase model, as the Purchase Register; capital purchases live in
 *   the Purchase VAT Book) dated in the period. The register totals posted
 *   purchases only with the same filters, so the Total column sums to it.
 * - returns: POSTED debit notes dated in the period (stored
 *   `debit_note_number`, `total`, `tds_amount`), attributed to the original
 *   purchase's supplier, or to the note's own supplier when unlinked.
 * - net: total less returns. TDS: TDS withheld on the bills less the TDS the
 *   debit notes gave back.
 * - paid: Purchase::settledAtPosting() on the period's bills plus every
 *   posted payment to the supplier dated in the period. A payment carries no
 *   store and no bill payment mode, so with the store or payment-mode filter
 *   on, only the payment allocations to bills that match the filters are
 *   counted (an unallocated advance has no store and drops out); otherwise
 *   the whole payment amount counts and only the branch filter narrows it.
 * - outstanding: Purchase::outstandingAmounts() (the batch form of
 *   outstandingAmount(), two queries per batch) summed over the supplier's
 *   posted bills dated up to the end date that match the filters. It reads
 *   the bills' current state, so a payment after the end date is already
 *   reflected (the Aged Payables basis). Opening ledger balances no bill
 *   carries are not included: the Creditors list is the ledger report.
 *
 * The drill-down (`supplier_id` set) is this same report narrowed to one
 * supplier, so its totals equal the parent row by construction. Screen and
 * Excel export share report().
 */
class PartyWisePurchaseReportController extends Controller
{
    /** Row key for a debit note with no supplier at all. */
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

        return Inertia::render('Tenant/Reports/PartyWisePurchase', [
            ...$report,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'stores' => BranchStock::storeOptions($branchFilter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'branchReport' => $branchFilter->payload(),
        ]);
    }

    /**
     * The same rows and totals as index() as a real .xlsx: the supplier
     * summary, or the selected supplier's documents when `supplier_id` is set.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $branchFilter = BranchFilter::fromRequest($request);
        $report = $this->report($request, $branchFilter);

        $suffix = $report['supplierId'] ? "-supplier-{$report['supplierId']}" : '';

        return Excel::download(
            new PartyWisePurchaseExport($report, $branchFilter->isActive() ? $branchFilter->label() : null),
            "supplier-wise-purchase-{$report['from']}-to-{$report['to']}{$suffix}.xlsx",
        );
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>, detail: array<string, mixed>|null, from: string, to: string, supplierId: int|null, storeId: int|null, paymentMode: string|null, search: string}
     */
    private function report(Request $request, BranchFilter $branchFilter): array
    {
        $filters = $this->filters($request, $branchFilter);
        $from = $filters['from'];
        $to = $filters['to'];

        $purchases = $this->purchasesQuery($filters, $branchFilter)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->orderBy('date')
            ->orderBy('id')
            ->get([
                'id', 'supplier_id', 'date', 'purchase_number', 'bill_number', 'payment_mode', 'taxable_amount',
                'nontaxable_amount', 'vat_amount', 'total', 'tds_amount', 'cash_amount', 'bank_amount', 'branch_id',
            ]);

        [$returns, $returnPurchases] = $this->periodReturns($filters, $branchFilter);

        $payments = $this->periodPayments($filters, $branchFilter);

        $returnKey = fn (PurchaseReturn $return): int => $this->returnPartyKey($return, $returnPurchases);

        $activePartyIds = $purchases->pluck('supplier_id')
            ->concat($returns->map($returnKey))
            ->concat($payments->pluck('supplier_id'))
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === self::NO_PARTY)
            ->unique()
            ->values();

        $outstandingByPurchase = $this->outstandingByPurchase($filters, $branchFilter, $activePartyIds);

        $accumulators = [];
        $entry = function (int $key) use (&$accumulators): void {
            $accumulators[$key] ??= [
                'documents' => 0,
                'returns_count' => 0,
                ...array_fill_keys(['taxable_amount', 'nontaxable_amount', 'vat_amount', 'total', 'returns_total', 'tds_amount', 'received', 'outstanding'], Money::zero()),
            ];
        };

        foreach ($purchases as $purchase) {
            $key = (int) $purchase->supplier_id;
            $entry($key);
            $row = &$accumulators[$key];
            $row['documents']++;
            $row['taxable_amount'] = $row['taxable_amount']->plus(Money::of($purchase->taxable_amount));
            $row['nontaxable_amount'] = $row['nontaxable_amount']->plus(Money::of($purchase->nontaxable_amount));
            $row['vat_amount'] = $row['vat_amount']->plus(Money::of($purchase->vat_amount));
            $row['total'] = $row['total']->plus(Money::of($purchase->total));
            $row['tds_amount'] = $row['tds_amount']->plus(Money::of($purchase->tds_amount ?? '0'));
            $row['received'] = $row['received']->plus($purchase->settledAtPosting());
            unset($row);
        }

        foreach ($returns as $return) {
            $key = $returnKey($return);
            $entry($key);
            $accumulators[$key]['returns_count']++;
            $accumulators[$key]['returns_total'] = $accumulators[$key]['returns_total']->plus(Money::of($return->total));
            $accumulators[$key]['tds_amount'] = $accumulators[$key]['tds_amount']->minus(Money::of($return->tds_amount ?? '0'));
        }

        foreach ($payments as $payment) {
            $key = (int) $payment->supplier_id;
            $entry($key);
            $accumulators[$key]['received'] = $accumulators[$key]['received']->plus(Money::of($payment->amount));
        }

        foreach ($outstandingByPurchase as $purchaseOutstanding) {
            $key = $purchaseOutstanding['supplier_id'];

            if (isset($accumulators[$key])) {
                $accumulators[$key]['outstanding'] = $accumulators[$key]['outstanding']->plus($purchaseOutstanding['amount']);
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

        $detail = $filters['supplierId']
            ? $this->detail($filters['supplierId'], $rows, $purchases, $returns, $returnPurchases, $payments, $outstandingByPurchase, $parties, $branchFilter)
            : null;

        return [
            'rows' => $rows->all(),
            'totals' => $this->totalsFor($rows, self::ROW_AMOUNTS, ['documents', 'returns_count']),
            'detail' => $detail,
            'from' => $from,
            'to' => $to,
            'supplierId' => $filters['supplierId'],
            'storeId' => $filters['storeId'],
            'paymentMode' => $filters['paymentMode'],
            'search' => $filters['search'],
        ];
    }

    /**
     * Strict filters: a malformed date, a reversed range or an unknown id is a
     * 422 the page shows, never a silent fallback.
     *
     * @return array{from: string, to: string, supplierId: int|null, storeId: int|null, paymentMode: string|null, search: string}
     */
    private function filters(Request $request, BranchFilter $branchFilter): array
    {
        $request->validate([
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'payment_mode' => ['nullable', 'string', 'in:'.implode(',', self::PAYMENT_MODES)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        [$from, $to] = $this->resolveDateRange($request);
        $paymentMode = $request->string('payment_mode')->toString();

        return [
            'from' => $from,
            'to' => $to,
            'supplierId' => $request->integer('supplier_id') ?: null,
            'storeId' => $branchFilter->allowedStoreId($request->integer('store_id') ?: null),
            'paymentMode' => $paymentMode !== '' ? $paymentMode : null,
            'search' => $request->string('search')->trim()->toString(),
        ];
    }

    /**
     * The posted payments dated in the period, one object per row with `id`
     * (the payment), `supplier_id` and `amount`. Without a store or
     * payment-mode filter that is every payment to the in-scope suppliers.
     * With either filter it is the payment ALLOCATIONS to posted bills
     * matching the filters (any bill date; the supplier is the bill's), so
     * the figure reconciles with the filtered documents; a payment can appear
     * once per bill it settles and is counted by `id`.
     *
     * @param  array{from: string, to: string, supplierId: int|null, storeId: int|null, paymentMode: string|null, search: string}  $filters
     * @return Collection<int, object>
     */
    private function periodPayments(array $filters, BranchFilter $branchFilter): Collection
    {
        if ($filters['storeId'] === null && $filters['paymentMode'] === null) {
            return Payment::query()
                ->where('status', 'posted')
                ->whereDate('date', '>=', $filters['from'])
                ->whereDate('date', '<=', $filters['to'])
                ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'payments.branch_id'))
                ->when($this->partyScope($filters), fn (Builder $query, Builder $scope) => $query->whereIn('supplier_id', $scope))
                ->get(['id', 'supplier_id', 'amount'])
                ->toBase();
        }

        return DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->join('purchases', 'purchases.id', '=', 'payment_allocations.purchase_id')
            ->where('payments.status', 'posted')
            ->whereDate('payments.date', '>=', $filters['from'])
            ->whereDate('payments.date', '<=', $filters['to'])
            ->tap(fn ($query) => $branchFilter->applyToDocuments($query, 'payments.branch_id'))
            ->whereIn('payment_allocations.purchase_id', $this->purchasesQuery($filters, $branchFilter)->select('purchases.id'))
            ->get(['payments.id as id', 'purchases.supplier_id as supplier_id', 'payment_allocations.amount as amount']);
    }

    /**
     * Posted purchases matching every filter except the date, shared by the
     * period rows and the outstanding figure.
     *
     * @param  array{supplierId: int|null, storeId: int|null, paymentMode: string|null, search: string}  $filters
     * @return Builder<Purchase>
     */
    private function purchasesQuery(array $filters, BranchFilter $branchFilter): Builder
    {
        return Purchase::query()
            ->where('status', 'posted')
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'purchases.branch_id'))
            ->when($filters['storeId'], fn (Builder $query, int $storeId) => $query->where('store_id', $storeId))
            ->when($filters['paymentMode'], fn (Builder $query, string $mode) => $query->where('payment_mode', $mode))
            ->when($this->partyScope($filters), fn (Builder $query, Builder $scope) => $query->whereIn('supplier_id', $scope));
    }

    /**
     * The suppliers the supplier and search filters allow, as a subquery
     * (search matches the supplier's name or PAN), or null for everyone.
     *
     * @param  array{supplierId: int|null, search: string}  $filters
     * @return Builder<Supplier>|null
     */
    private function partyScope(array $filters): ?Builder
    {
        if ($filters['supplierId'] === null && $filters['search'] === '') {
            return null;
        }

        $search = $filters['search'];
        $pattern = '%'.addcslashes($search, '\\%_').'%';

        return Supplier::query()
            ->select('id')
            ->when($filters['supplierId'], fn (Builder $query, int $id) => $query->whereKey($id))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($pattern) {
                $inner->where('name', 'like', $pattern)->orWhere('tpin', 'like', $pattern);
            }));
    }

    /**
     * Posted debit notes dated in the period, plus the supplier and bill
     * number of each linked note's purchase, read with the query builder (no
     * branch access scope) so the owning supplier never depends on who looks.
     * A payment-mode filter keeps only notes against bills of that mode.
     *
     * @param  array{from: string, to: string, supplierId: int|null, storeId: int|null, paymentMode: string|null, search: string}  $filters
     * @return array{0: Collection<int, PurchaseReturn>, 1: Collection<int, object>}
     */
    private function periodReturns(array $filters, BranchFilter $branchFilter): array
    {
        $scope = $this->partyScope($filters);

        $returns = PurchaseReturn::query()
            ->where('status', 'posted')
            ->whereDate('date', '>=', $filters['from'])
            ->whereDate('date', '<=', $filters['to'])
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'purchase_returns.branch_id'))
            ->when($filters['storeId'], fn (Builder $query, int $storeId) => $query->where('store_id', $storeId))
            ->when($filters['paymentMode'], fn (Builder $query, string $mode) => $query->whereIn(
                'purchase_id',
                DB::table('purchases')->select('id')->where('payment_mode', $mode),
            ))
            ->when($scope, fn (Builder $query) => $query->where(function (Builder $inner) use ($scope) {
                $inner->whereIn('purchase_id', DB::table('purchases')->select('id')->whereIn('supplier_id', $scope))
                    ->orWhere(fn (Builder $unlinked) => $unlinked->whereNull('purchase_id')->whereIn('supplier_id', $scope));
            }))
            ->orderBy('date')
            ->orderBy('id')
            ->get(['id', 'purchase_id', 'supplier_id', 'debit_note_number', 'date', 'total', 'tds_amount', 'branch_id']);

        $returnPurchases = collect();

        foreach ($returns->pluck('purchase_id')->filter()->unique()->chunk(self::CHUNK) as $purchaseIds) {
            $returnPurchases = $returnPurchases->union(
                DB::table('purchases')->whereIn('id', $purchaseIds->values()->all())->get(['id', 'supplier_id', 'bill_number'])->keyBy('id'),
            );
        }

        return [$returns, $returnPurchases];
    }

    /**
     * The supplier a debit note belongs to: its purchase's supplier when
     * linked, its own supplier when unlinked, NO_PARTY when neither is known.
     *
     * @param  Collection<int, object>  $returnPurchases
     */
    private function returnPartyKey(PurchaseReturn $return, Collection $returnPurchases): int
    {
        $purchaseSupplierId = $return->purchase_id !== null ? $returnPurchases->get($return->purchase_id)?->supplier_id : null;

        return (int) ($purchaseSupplierId ?? $return->supplier_id ?? self::NO_PARTY);
    }

    /**
     * Purchase::outstandingAmounts() for every posted bill of the active
     * suppliers dated up to the end date and matching the filters, keyed by
     * purchase id. The batch method is the exact outstandingAmount() rule in
     * two queries per batch.
     *
     * @param  array{to: string, supplierId: int|null, storeId: int|null, paymentMode: string|null, search: string}  $filters
     * @param  Collection<int, int>  $partyIds
     * @return array<int, array{supplier_id: int, amount: Money}>
     */
    private function outstandingByPurchase(array $filters, BranchFilter $branchFilter, Collection $partyIds): array
    {
        $outstanding = [];

        foreach ($partyIds->chunk(self::CHUNK) as $chunk) {
            $purchases = $this->purchasesQuery($filters, $branchFilter)
                ->whereDate('date', '<=', $filters['to'])
                ->whereIn('supplier_id', $chunk->values()->all())
                ->get(['id', 'supplier_id', 'payment_mode', 'total', 'tds_amount', 'cash_amount', 'bank_amount']);

            foreach ($purchases->chunk(self::CHUNK) as $batch) {
                $amounts = Purchase::outstandingAmounts($batch);

                foreach ($batch as $purchase) {
                    $outstanding[$purchase->id] = ['supplier_id' => (int) $purchase->supplier_id, 'amount' => $amounts[$purchase->id]];
                }
            }
        }

        return $outstanding;
    }

    /**
     * @param  list<int>  $keys
     * @return Collection<int, Supplier>
     */
    private function parties(array $keys): Collection
    {
        $ids = array_values(array_filter($keys, fn (int $key) => $key !== self::NO_PARTY));
        $parties = collect();

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $parties = $parties->union(Supplier::query()->whereIn('id', $chunk)->get(['id', 'name', 'tpin'])->keyBy('id'));
        }

        return $parties;
    }

    /**
     * @param  array<string, mixed>  $row  Money accumulators
     * @return array<string, mixed>
     */
    private function partyRow(int $key, array $row, ?Supplier $supplier): array
    {
        $amounts = [];

        foreach (['taxable_amount', 'nontaxable_amount', 'vat_amount', 'total', 'returns_total', 'tds_amount', 'received', 'outstanding'] as $amountKey) {
            $amounts[$amountKey] = $row[$amountKey]->toString();
        }

        return [
            'party_id' => $key === self::NO_PARTY ? null : $key,
            'party' => $key === self::NO_PARTY ? 'No supplier recorded' : ($supplier?->name ?? "Supplier #{$key}"),
            'pan' => $supplier?->tpin,
            'is_walk_in' => false,
            'documents' => $row['documents'],
            'returns_count' => $row['returns_count'],
            ...$amounts,
            'net_total' => $row['total']->minus($row['returns_total'])->toString(),
            'sort_group' => $key === self::NO_PARTY ? 1 : 0,
        ];
    }

    /**
     * The selected supplier's documents, each list the parent report's own
     * collection narrowed to this supplier so every total equals the row.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<int, Purchase>  $purchases
     * @param  Collection<int, PurchaseReturn>  $returns
     * @param  Collection<int, object>  $returnPurchases
     * @param  Collection<int, object>  $payments
     * @param  array<int, array{supplier_id: int, amount: Money}>  $outstandingByPurchase
     * @param  Collection<int, Supplier>  $parties
     * @return array<string, mixed>
     */
    private function detail(
        int $supplierId,
        Collection $rows,
        Collection $purchases,
        Collection $returns,
        Collection $returnPurchases,
        Collection $payments,
        array $outstandingByPurchase,
        Collection $parties,
        BranchFilter $branchFilter,
    ): array {
        $row = $rows->firstWhere('party_id', $supplierId);
        $supplier = $parties->get($supplierId) ?? Supplier::query()->find($supplierId, ['id', 'name', 'tpin']);

        $documents = $purchases
            ->filter(fn (Purchase $purchase) => (int) $purchase->supplier_id === $supplierId)
            ->values()
            ->map(function (Purchase $purchase, int $index) use ($outstandingByPurchase, $branchFilter) {
                $outstanding = $outstandingByPurchase[$purchase->id]['amount'] ?? Money::zero();
                $due = Money::of($purchase->total)->minus(Money::of($purchase->tds_amount ?? '0'));

                return [
                    'sn' => $index + 1,
                    'id' => $purchase->id,
                    'date' => $purchase->date->toDateString(),
                    'number' => $purchase->purchase_number,
                    'bill_number' => $purchase->bill_number,
                    'payment_mode' => $purchase->payment_mode,
                    'taxable_amount' => $purchase->taxable_amount,
                    'nontaxable_amount' => $purchase->nontaxable_amount,
                    'vat_amount' => $purchase->vat_amount,
                    'total' => $purchase->total,
                    'tds_amount' => Money::of($purchase->tds_amount ?? '0')->toString(),
                    'received_at_billing' => $purchase->settledAtPosting()->toString(),
                    'outstanding' => $outstanding->toString(),
                    'payment_status' => $this->paymentStatus($outstanding, $due),
                    ...$branchFilter->rowBranch($purchase->branch_id),
                ];
            });

        $partyReturns = $returns
            ->filter(fn (PurchaseReturn $return) => $this->returnPartyKey($return, $returnPurchases) === $supplierId)
            ->values()
            ->map(fn (PurchaseReturn $return, int $index) => [
                'sn' => $index + 1,
                'id' => $return->id,
                'date' => $return->date->toDateString(),
                'number' => $return->debit_note_number,
                'against' => $return->purchase_id !== null ? $returnPurchases->get($return->purchase_id)?->bill_number : null,
                'total' => $return->total,
                'tds_amount' => Money::of($return->tds_amount ?? '0')->toString(),
                ...$branchFilter->rowBranch($return->branch_id),
            ]);

        $partyPayments = $payments->filter(fn (object $payment) => (int) $payment->supplier_id === $supplierId);
        $documentTotals = $this->totalsFor($documents, self::DOCUMENT_AMOUNTS);
        $rowOutstanding = Money::of($row['outstanding'] ?? '0');

        return [
            'party' => [
                'id' => $supplierId,
                'name' => $supplier?->name ?? "Supplier #{$supplierId}",
                'pan' => $supplier?->tpin,
                'is_walk_in' => false,
            ],
            'documents' => $documents->all(),
            'documentTotals' => $documentTotals,
            'returns' => $partyReturns->all(),
            'returnTotals' => $this->totalsFor($partyReturns, ['total', 'tds_amount']),
            'receipts' => [
                'count' => $partyPayments->pluck('id')->unique()->count(),
                'amount' => Money::sum($partyPayments->map(fn (object $payment) => Money::of($payment->amount)))->toString(),
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
     * Totals summed in Money over exactly the rows returned.
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
