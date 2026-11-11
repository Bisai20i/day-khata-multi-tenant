<?php

namespace App\Support\Branches\Audit;

use App\Enums\FiscalYearStatus;
use App\Enums\InventoryClass;
use App\Enums\StockMovementType;
use App\Models\FiscalYear;
use App\Models\MaterialIssueItem;
use App\Models\ProductionCompletion;
use App\Models\ProductionEntry;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturnLine;
use App\Models\SaleLine;
use App\Models\SaleReturnLine;
use App\Models\StockAdjustmentLine;
use App\Models\StockConversionLine;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Support\Branches\BranchAudit;
use App\Support\Inventory\StockCosting;
use App\Support\Manufacturing\InventoryReconciliation;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Stock invariants of plan 12: I4 (the branches' stock adds up to the
 * company's), I5 (stores belong to one real branch, and a store-bearing
 * document and each of its stock movements sit in the document's branch)
 * and I8 (perpetual classes reconcile per branch, and since BR25 every
 * raw material or finished goods leg of a transfer between branches keeps
 * its dispatch cost), plus the WARN list of transfers still in transit
 * (whole value, every class).
 *
 * Branch is never stored on a stock movement (section 5.4): it is the
 * movement's store's branch. So I4 reduces to "every movement's store has
 * a real branch" (the per-branch valuation's consistency with the company
 * valuation is also checked, but is near an identity; see branchTotals()),
 * and I5 is checked by joining each movement to its document through the
 * movement's reference.
 */
final class StockChecks
{
    /**
     * Header tables that carry both store_id and branch_id.
     *
     * @var list<string>
     */
    private const STORE_DOCUMENTS = [
        'sales', 'purchases', 'sales_returns', 'purchase_returns', 'quotations', 'capital_purchases', 'capital_sales',
        'stock_adjustments', 'stock_conversions', 'material_issue_notes', 'production_entries',
    ];

    /**
     * Stock movement reference (a document line, or a production
     * completion, which a ProductionReceipt movement references instead of
     * its entry) => its belongsTo relation to the document header.
     *
     * @var array<class-string<Model>, string>
     */
    private const LINE_REFERENCES = [
        SaleLine::class => 'sale',
        PurchaseLine::class => 'purchase',
        SaleReturnLine::class => 'salesReturn',
        PurchaseReturnLine::class => 'purchaseReturn',
        StockAdjustmentLine::class => 'stockAdjustment',
        StockConversionLine::class => 'stockConversion',
        MaterialIssueItem::class => 'materialIssueNote',
        ProductionCompletion::class => 'productionEntry',
    ];

    public function __construct(private readonly AuditContext $context) {}

    /**
     * I4. (a) Every stock movement's store exists and belongs to an existing
     * branch, so no quantity falls outside every branch. This is the part
     * that carries I4: branch is never stored on a movement, so a movement
     * in a store of a real branch is in exactly one branch's stock.
     *
     * (b) A consistency check of the per-branch valuation
     * (StockCosting::branchValuationRows(), the figure the year-end close
     * and the branch reports use) as at the audit date: its per-item branch
     * split adds up to its own company row, and its company total equals
     * StockCosting::totalClosingValue(). Both sides come from the same
     * movements through closely related code, so (b) is close to an
     * identity: it catches a split or rounding bug in that code, not a data
     * problem, and is not independent evidence for I4.
     */
    public function branchTotals(): BranchAuditResult
    {
        $findings = new AuditFindings;

        $orphans = DB::table('item_stock_movements as m')
            ->leftJoin('stores as s', 's.id', '=', 'm.store_id')
            ->leftJoin('branches as b', 'b.id', '=', 's.branch_id')
            ->whereNull('b.id');
        [$count, $ids] = AuditContext::distinctIds($orphans, 'm.id');
        $findings->add($count, array_map(fn (int $id): string => "Stock movement #{$id} is in a store without a known branch", $ids));

        $message = 'No stock valued yet.';

        if ($this->context->mainBranchId !== null && $this->context->branches !== []) {
            $storeBranches = DB::table('stores')->orderBy('id')->pluck('branch_id', 'id')
                ->mapWithKeys(fn (mixed $branchId, mixed $storeId): array => [(int) $storeId => (int) $branchId])
                ->all();

            $rows = StockCosting::branchValuationRows(
                $this->context->asOf,
                array_keys($this->context->branches),
                $storeBranches,
                $this->context->mainBranchId,
                [],
                true,
            );

            $branchValues = [];

            foreach ($rows as $row) {
                $splitQuantity = Quantity::sum(array_map(fn (array $split): Quantity => $split['quantity'], $row['by_branch'] ?? []));
                $splitValue = Money::sum(array_map(fn (array $split): Money => $split['value'], $row['by_branch'] ?? []));

                foreach ($row['by_branch'] ?? [] as $branchId => $split) {
                    $branchValues[$branchId] = ($branchValues[$branchId] ?? Money::zero())->plus($split['value']);
                }

                if (! $splitQuantity->isEqualTo($row['quantity']) || ! $splitValue->isEqualTo($row['value'])) {
                    $findings->addOne(sprintf(
                        'Item #%d %s: branches add up to %s / %s, company row is %s / %s',
                        $row['item_id'],
                        $row['name'],
                        $splitQuantity->toString(),
                        $splitValue->toString(),
                        $row['quantity']->toString(),
                        $row['value']->toString(),
                    ));
                }
            }

            $branchTotal = Money::sum(array_map(fn (array $row): Money => $row['value'], $rows->all()));
            $companyTotal = StockCosting::totalClosingValue($this->context->asOf);

            if (! $branchTotal->isEqualTo($companyTotal)) {
                $findings->addOne(sprintf(
                    'Stock value as at %s: branches add up to %s, company valuation is %s (difference %s)',
                    $this->context->asOf,
                    $branchTotal->toString(),
                    $companyTotal->toString(),
                    $branchTotal->minus($companyTotal)->toString(),
                ));
            }

            $perBranch = [];

            foreach ($branchValues as $branchId => $value) {
                if (! $value->isZero()) {
                    $perBranch[] = $this->context->branches[$branchId]->name.' '.$value->toString();
                }
            }

            $message = sprintf(
                'Every stock movement is in a store of a known branch; the branch valuation as at %s is consistent: company %s%s.',
                $this->context->asOf,
                $companyTotal->toString(),
                $perBranch === [] ? '' : ' = '.implode(' + ', $perBranch),
            );
        }

        return $findings->result(
            'stock.branch_totals',
            'I4',
            'Branch stock adds up to company stock',
            BranchAuditResult::FAIL,
            $message,
            ':count stock problem(s): movements in a store of no known branch, or a branch valuation split that does not match its own company figure.',
        );
    }

    /**
     * I8: InventoryReconciliation::checkByBranch() for the open fiscal year
     * as at the audit date (capped at the year's end): per branch, the
     * raw material, WIP and finished goods ledger balances equal the stock
     * records. The same check the year-end close runs.
     */
    public function perpetualByBranch(): BranchAuditResult
    {
        $title = 'Perpetual stock reconciles per branch';
        $openYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

        if ($openYear === null) {
            return BranchAuditResult::pass('stock.perpetual_by_branch', 'I8', $title, 'No open fiscal year; nothing to reconcile.');
        }

        $asOf = min($this->context->asOf, $openYear->end_date->toDateString());
        $findings = new AuditFindings;

        foreach (InventoryReconciliation::checkByBranch($openYear, $asOf) as $branchId => $classes) {
            foreach ($classes as $class => $row) {
                if (! $row['difference']->isZero()) {
                    $findings->addOne(sprintf(
                        '%s, %s: ledger %s, stock records %s, difference %s',
                        $this->context->branchName($branchId),
                        str_replace('_', ' ', (string) $class),
                        $row['gl']->toString(),
                        $row['subledger']->toString(),
                        $row['difference']->toString(),
                    ));
                }
            }
        }

        return $findings->result(
            'stock.perpetual_by_branch',
            'I8',
            $title,
            BranchAuditResult::FAIL,
            "Raw material, WIP and finished goods reconcile in every branch as at {$asOf}.",
            ':count branch class(es) where the ledger and the stock records differ.',
        );
    }

    /**
     * BR25: every stock movement (not cancelled) of a raw material or
     * finished goods line of a transfer between branches carries cost_value
     * equal to the line's line_value: the dispatch took that cost out of the
     * sender's store and AS12 / AS14, and the receipt must put back exactly
     * it, never a recomputed average, or the two branches' class accounts
     * and stock records drift apart (I8) while clearing still nets. A
     * missing cost_value counts as a mismatch. Transfers within one branch
     * are left out: their legs are costed at the moving average and their
     * line_value is only a stated value. A WARN, and nothing checked, before
     * the BR25 migration (no stock_transfer_lines.inventory_class).
     */
    public function perpetualTransferCosts(): BranchAuditResult
    {
        $title = 'Perpetual transfer legs keep the dispatch cost';

        if (! $this->context->hasColumn('stock_transfer_lines', 'inventory_class') || ! $this->context->hasColumn('item_stock_movements', 'cost_value')) {
            return BranchAuditResult::warn('stock.perpetual_transfer_costs', 'I8', $title, 'stock_transfer_lines.inventory_class or item_stock_movements.cost_value is missing; run the tenant migrations. Nothing to check.');
        }

        $perpetualClasses = array_values(array_map(
            fn (InventoryClass $class): string => $class->value,
            array_filter(InventoryClass::cases(), fn (InventoryClass $class): bool => $class->isPerpetual()),
        ));

        $transferLine = new StockTransferLine;
        $legs = $this->movementsOf($transferLine->getMorphClass())
            ->join($transferLine->getTable().' as l', 'l.id', '=', 'm.reference_id')
            ->join('stock_transfers as h', 'h.id', '=', 'l.stock_transfer_id')
            ->whereColumn('h.from_branch_id', '!=', 'h.to_branch_id')
            ->whereIn('l.inventory_class', $perpetualClasses)
            ->where('m.cancelled', false);

        $total = (clone $legs)->count();

        $mismatches = $legs->where(fn (QueryBuilder $bad) => $bad
            ->whereNull('m.cost_value')
            ->orWhereRaw(AuditContext::paisa('m.cost_value').' <> '.AuditContext::paisa('l.line_value')));

        $findings = new AuditFindings;
        $count = (clone $mismatches)->count();

        if ($count > 0) {
            $rows = $mismatches->orderBy('m.id')->limit(BranchAudit::LIST_LIMIT)
                ->selectRaw(
                    'm.id as movement_id, m.movement_type as movement_type, h.id as transfer_id, '
                    .'CASE WHEN m.cost_value IS NULL THEN 1 ELSE 0 END as cost_missing, '
                    .AuditContext::paisa('m.cost_value').' as cost_scaled, '
                    .AuditContext::paisa('l.line_value').' as line_scaled'
                )
                ->get();

            $findings->add($count, $rows->map(fn (object $row): string => sprintf(
                'Stock movement #%d (%s of transfer #%d) carries cost %s, the line was dispatched at %s',
                $row->movement_id,
                $row->movement_type,
                $row->transfer_id,
                (int) $row->cost_missing === 1 ? 'none' : AuditContext::money($row->cost_scaled)->toString(),
                AuditContext::money($row->line_scaled)->toString(),
            ))->all());
        }

        return $findings->result(
            'stock.perpetual_transfer_costs',
            'I8',
            $title,
            BranchAuditResult::FAIL,
            $total === 0 ? 'No raw material or finished goods transferred between branches yet.' : "All {$total} raw material and finished goods transfer leg(s) carry the cost they were dispatched at.",
            ':count raw material or finished goods transfer leg(s) whose cost differs from the value the line was dispatched at.',
        );
    }

    /**
     * I5, stores: every store belongs to an existing branch, and a branch's
     * default store is one of its own stores.
     */
    public function stores(): BranchAuditResult
    {
        $findings = new AuditFindings;

        $orphans = DB::table('stores as s')
            ->leftJoin('branches as b', 'b.id', '=', 's.branch_id')
            ->whereNull('b.id');
        [$count, $ids] = AuditContext::distinctIds($orphans, 's.id');
        $findings->add($count, array_map(fn (int $id): string => "Store #{$id} belongs to no known branch", $ids));

        $defaults = DB::table('branches as b')
            ->join('stores as s', 's.id', '=', 'b.default_store_id')
            ->whereColumn('s.branch_id', '!=', 'b.id');
        [$count, $ids] = AuditContext::distinctIds($defaults, 'b.id');
        $findings->add($count, array_map(fn (int $id): string => "{$this->context->branchName($id)} has a default store of another branch", $ids));

        $total = DB::table('stores')->count();

        return $findings->result(
            'stock.stores',
            'I5',
            'Every store belongs to one branch',
            BranchAuditResult::FAIL,
            "All {$total} store(s) belong to an existing branch; every default store is the branch's own.",
            ':count store problem(s).',
        );
    }

    /**
     * I5, documents: a store-bearing document's branch is its store's
     * branch; a transfer's from/to branch is its from/to store's branch.
     * Every status is included: a cancelled document's movements still
     * happened in that store.
     */
    public function documentStores(): BranchAuditResult
    {
        $findings = new AuditFindings;

        foreach (self::STORE_DOCUMENTS as $table) {
            if (! $this->context->hasColumn($table, 'store_id') || ! $this->context->hasColumn($table, 'branch_id')) {
                continue;
            }

            $this->addStoreMismatches($findings, $table, 'store_id', 'branch_id', $table);
        }

        $this->addStoreMismatches($findings, 'stock_transfers', 'from_store_id', 'from_branch_id', 'stock_transfers (from)');
        $this->addStoreMismatches($findings, 'stock_transfers', 'to_store_id', 'to_branch_id', 'stock_transfers (to)');

        return $findings->result(
            'stock.document_stores',
            'I5',
            'Documents sit in their store\'s branch',
            BranchAuditResult::FAIL,
            'Every store-bearing document and transfer end is in its store\'s branch.',
            ':count document(s) whose branch is not their store\'s branch (or whose store is missing).',
        );
    }

    /**
     * I5, movements: each stock movement's store is in its document's
     * branch (a transfer's out leg in the sending branch, its in leg in the
     * receiving one). Catches a store whose branch was changed after use.
     * Movements whose reference is not a known document line are skipped.
     */
    public function movementStores(): BranchAuditResult
    {
        $findings = new AuditFindings;

        foreach (self::LINE_REFERENCES as $lineClass => $relationName) {
            /** @var Model $line */
            $line = new $lineClass;
            /** @var BelongsTo<Model, Model> $relation */
            $relation = $line->{$relationName}();
            $headerTable = $relation->getRelated()->getTable();

            $query = $this->movementsOf($line->getMorphClass())
                ->join($line->getTable().' as l', 'l.id', '=', 'm.reference_id')
                ->join($headerTable.' as h', 'h.id', '=', 'l.'.$relation->getForeignKeyName())
                ->whereColumn('s.branch_id', '!=', 'h.branch_id');

            $this->addMovementMismatches($findings, $query, $headerTable, 'h.branch_id');
        }

        $entry = new ProductionEntry;
        $this->addMovementMismatches(
            $findings,
            $this->movementsOf($entry->getMorphClass())
                ->join($entry->getTable().' as h', 'h.id', '=', 'm.reference_id')
                ->whereColumn('s.branch_id', '!=', 'h.branch_id'),
            $entry->getTable(),
            'h.branch_id',
        );

        $transferLine = new StockTransferLine;
        $transfers = $this->movementsOf($transferLine->getMorphClass())
            ->join($transferLine->getTable().' as l', 'l.id', '=', 'm.reference_id')
            ->join('stock_transfers as h', 'h.id', '=', 'l.stock_transfer_id')
            ->where(fn (QueryBuilder $legs) => $legs
                ->where(fn (QueryBuilder $out) => $out
                    ->where('m.movement_type', StockMovementType::TransferOut->value)
                    ->whereColumn('s.branch_id', '!=', 'h.from_branch_id'))
                ->orWhere(fn (QueryBuilder $in) => $in
                    ->where('m.movement_type', StockMovementType::TransferIn->value)
                    ->whereColumn('s.branch_id', '!=', 'h.to_branch_id')));
        $this->addMovementMismatches($findings, $transfers, 'stock_transfers', "CASE WHEN m.movement_type = '".StockMovementType::TransferOut->value."' THEN h.from_branch_id ELSE h.to_branch_id END");

        $total = DB::table('item_stock_movements')->count();

        return $findings->result(
            'stock.movement_stores',
            'I5',
            'Stock movements sit in their document\'s branch',
            BranchAuditResult::FAIL,
            "All {$total} stock movement(s) with a known document are in a store of that document's branch.",
            ':count stock movement(s) in a store of another branch than their document.',
        );
    }

    /**
     * WARN: transfers between branches dispatched and not yet received or
     * cancelled, oldest first, with their age. They block the year-end
     * close and leave each side's clearing balance apart.
     */
    public function transfersInTransit(): BranchAuditResult
    {
        $query = DB::table('stock_transfers')
            ->where('status', StockTransfer::STATUS_IN_TRANSIT);

        $count = (clone $query)->count();

        if ($count === 0) {
            return BranchAuditResult::pass('stock.in_transit', '-', 'Transfers in transit', 'No stock transfer is in transit.');
        }

        $value = AuditContext::money((clone $query)->selectRaw('SUM('.AuditContext::paisa('total_value').') as value_scaled')->value('value_scaled'));
        $asOf = CarbonImmutable::parse($this->context->asOf);
        $findings = new AuditFindings;

        $rows = (clone $query)->orderBy('date')->orderBy('id')->limit(BranchAudit::LIST_LIMIT)
            ->selectRaw('id, date, from_branch_id, to_branch_id, '.AuditContext::paisa('total_value').' as value_scaled')
            ->get();

        $findings->add($count, $rows->map(fn (object $row): string => sprintf(
            'Transfer #%d, %s -> %s, dated %s (%d day(s) ago), value %s',
            $row->id,
            $this->context->branchName($row->from_branch_id),
            $this->context->branchName($row->to_branch_id),
            substr((string) $row->date, 0, 10),
            (int) CarbonImmutable::parse(substr((string) $row->date, 0, 10))->diffInDays($asOf),
            AuditContext::money($row->value_scaled)->toString(),
        ))->all());

        return $findings->result(
            'stock.in_transit',
            '-',
            'Transfers in transit',
            BranchAuditResult::WARN,
            '',
            ":count stock transfer(s) in transit, value {$value->toString()}; receive or cancel them before closing the year.",
        );
    }

    private function movementsOf(string $morphClass): QueryBuilder
    {
        return DB::table('item_stock_movements as m')
            ->join('stores as s', 's.id', '=', 'm.store_id')
            ->where('m.reference_type', $morphClass);
    }

    private function addStoreMismatches(AuditFindings $findings, string $table, string $storeColumn, string $branchColumn, string $label): void
    {
        $query = DB::table($table.' as d')
            ->leftJoin('stores as s', 's.id', '=', 'd.'.$storeColumn)
            ->whereNotNull('d.'.$storeColumn)
            ->where(fn (QueryBuilder $bad) => $bad->whereNull('s.id')->orWhereColumn('s.branch_id', '!=', 'd.'.$branchColumn));

        $count = (clone $query)->count();

        if ($count === 0) {
            return;
        }

        $rows = $query->orderBy('d.id')->limit(BranchAudit::LIST_LIMIT)
            ->get(['d.id as id', 'd.'.$branchColumn.' as branch_id', 'd.'.$storeColumn.' as store_id', 's.branch_id as store_branch_id']);

        $findings->add($count, $rows->map(fn (object $row): string => sprintf(
            '%s #%d is in %s but its store #%d is in %s',
            $label,
            $row->id,
            $this->context->branchName($row->branch_id),
            $row->store_id,
            $row->store_branch_id === null ? 'no known store' : $this->context->branchName($row->store_branch_id),
        ))->all());
    }

    private function addMovementMismatches(AuditFindings $findings, QueryBuilder $query, string $documentTable, string $documentBranchExpression): void
    {
        $count = (clone $query)->count();

        if ($count === 0) {
            return;
        }

        $rows = $query->orderBy('m.id')->limit(BranchAudit::LIST_LIMIT)
            ->selectRaw("m.id as movement_id, h.id as document_id, s.branch_id as store_branch_id, {$documentBranchExpression} as document_branch_id")
            ->get();

        $findings->add($count, $rows->map(fn (object $row): string => sprintf(
            'Stock movement #%d (%s #%d) is in a store of %s, the document is in %s',
            $row->movement_id,
            $documentTable,
            $row->document_id,
            $this->context->branchName($row->store_branch_id),
            $this->context->branchName($row->document_branch_id),
        ))->all());
    }
}
