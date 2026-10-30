<?php

namespace App\Support\Manufacturing;

use App\Enums\FiscalYearStatus;
use App\Enums\InventoryClass;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Http\Controllers\Concerns\DescribesStockMovements;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\FiscalYearInventorySnapshot;
use App\Models\ItemStockMovement;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\ProductionWipEntry;
use App\Support\Inventory\PerpetualCosting;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The four manufacturing reports of MC10: the per-class stock ledgers, the
 * inventory schedule (a Balance Sheet note), the COGM / COGS schedule (a P&L
 * note) and the GL-versus-subledger reconciliation view.
 *
 * Every sum is a scaled-integer SQL SUM turned back into Money / Quantity
 * (SQLite gives a decimal column REAL affinity, so a plain SUM() there is a
 * float), and every comparison is exact. Nothing here ever posts: the
 * schedules are derived from the two books the perpetual model keeps in step
 * (the GL and the cost_value / WIP subledgers, invariants P1 and P2) so that
 * a disagreement between them shows up as a number on the page, never as a
 * silent plug. The COGS schedule in particular computes COGS from the
 * subledgers and then compares it with the EXE10 balance; any difference is
 * returned as its own reconciling line.
 */
class ManufacturingReports
{
    use DescribesStockMovements;

    public const CLASS_RAW_MATERIAL = 'raw_material';

    public const CLASS_WIP = 'wip';

    public const CLASS_FINISHED_GOOD = 'finished_good';

    /**
     * @return array<int, string>
     */
    public static function classKeys(): array
    {
        return [self::CLASS_RAW_MATERIAL, self::CLASS_WIP, self::CLASS_FINISHED_GOOD];
    }

    public static function classLabel(string $class): string
    {
        return match ($class) {
            self::CLASS_RAW_MATERIAL => 'Raw Material',
            self::CLASS_WIP => 'Work in Process',
            self::CLASS_FINISHED_GOOD => 'Finished Goods',
            default => $class,
        };
    }

    /**
     * The current fiscal year's bounds (the open one, else the latest), which
     * every manufacturing report defaults its date range to.
     *
     * @return array{0: string, 1: string}
     */
    public function defaultRange(): array
    {
        $fiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first()
            ?? FiscalYear::query()->orderByDesc('end_date')->first();

        return [
            $fiscalYear?->start_date->toDateString() ?? now()->startOfMonth()->toDateString(),
            $fiscalYear?->end_date->toDateString() ?? now()->toDateString(),
        ];
    }

    /**
     * The fiscal year the schedules read GL balances from: the one containing
     * `$date`, else the open year, else the latest. GL balances are per
     * fiscal year (the opening-balance voucher carries the prior year in), so
     * summing across years would count the carried-forward balance twice.
     */
    public function fiscalYearFor(string $date): ?FiscalYear
    {
        return FiscalYear::query()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first()
            ?? FiscalYear::query()->where('status', FiscalYearStatus::Open)->first()
            ?? FiscalYear::query()->orderByDesc('end_date')->first();
    }

    /**
     * One class's stock ledger: an opening row, one row per movement with a
     * running quantity and value, and the closing figures.
     *
     * RM / FG rows come from item_stock_movements.cost_value, WIP rows from
     * production_wip_entries. WIP has no meaningful running quantity (the
     * materials in it are in different units), so its quantity columns are
     * only filled on material rows and its balance quantity stays null.
     *
     * The opening comes from the prior fiscal year's verified snapshot when
     * the window starts the day after that year ended (the snapshot is next
     * year's opening by MC9), otherwise from the balance as of the day before.
     *
     * @return array{class: string, label: string, from: string, to: string, openingFromSnapshot: bool, opening: array{quantity: ?string, value: string}, rows: array<int, array<string, mixed>>, closing: array{quantity: ?string, value: string}}
     */
    public function stockLedger(string $class, string $from, string $to, ?int $itemId = null): array
    {
        return $class === self::CLASS_WIP
            ? $this->wipLedger($from, $to)
            : $this->movementLedger(InventoryClass::from($class), $from, $to, $itemId);
    }

    /**
     * Balance Sheet note: the stock lines by GL balance with a total that
     * equals the Balance Sheet's stock lines. Trading stock is the Stock in
     * Hand balance once the year-end pair has posted, otherwise the live
     * periodic closing value the Balance Sheet itself shows. The subledger
     * and difference columns repeat the reconciliation per perpetual class.
     *
     * @return array{asOf: string, fiscalYear: ?string, rows: array<int, array{key: string, label: string, gl: string, subledger: ?string, difference: ?string}>, total: string}
     */
    public function inventorySchedule(FiscalYear $fiscalYear, string $asOf): array
    {
        $gl = [
            self::CLASS_RAW_MATERIAL => $this->glBalance($fiscalYear, ManufacturingAccounts::RAW_MATERIAL, null, $asOf, false),
            self::CLASS_WIP => $this->glBalance($fiscalYear, ManufacturingAccounts::WIP, null, $asOf, false),
            self::CLASS_FINISHED_GOOD => $this->glBalance($fiscalYear, ManufacturingAccounts::FINISHED_GOODS, null, $asOf, false),
        ];

        $subledger = [
            self::CLASS_RAW_MATERIAL => PerpetualCosting::classValue(InventoryClass::RawMaterial, $asOf),
            self::CLASS_WIP => WipLedger::balance(null, $asOf),
            self::CLASS_FINISHED_GOOD => PerpetualCosting::classValue(InventoryClass::FinishedGood, $asOf),
        ];

        $rows = [];

        foreach (self::classKeys() as $key) {
            $rows[] = [
                'key' => $key,
                'label' => self::classLabel($key),
                'gl' => $gl[$key]->toString(),
                'subledger' => $subledger[$key]->toString(),
                'difference' => $gl[$key]->minus($subledger[$key])->toString(),
            ];
        }

        $trading = $this->tradingStock($fiscalYear, $asOf);

        $rows[] = [
            'key' => 'trading',
            'label' => 'Stock in Hand (trading items)',
            'gl' => $trading->toString(),
            'subledger' => null,
            'difference' => null,
        ];

        return [
            'asOf' => $asOf,
            'fiscalYear' => $fiscalYear->bs_label,
            'rows' => $rows,
            'total' => Money::sum([...array_values($gl), $trading])->toString(),
        ];
    }

    /**
     * P&L note: raw material consumed -> total manufacturing cost -> COGM ->
     * COGS (MC10), plus the EXE10 balance for the same period and the
     * reconciling line between the two. The reconciling line is never hidden:
     * a non-zero difference is flagged so the page and exports can show it
     * red.
     *
     * Two lines go beyond the spec's formula because the GL books them
     * elsewhere and the identity would otherwise leak: raw material sold
     * straight to a customer is charged to EXE10 at the sale, so it is taken
     * out of "consumed" and added back to COGS; finished goods bought in (not
     * made) are added to the FG pool. Stock adjustments post to EXE23, not
     * EXE10, so their net loss is deducted from consumption / COGS.
     *
     * @return array{from: string, to: string, fiscalYear: ?string, lines: array<int, array{key: string, label: string, amount: string, style: string}>, computedCogs: string, glCogs: string, difference: string, reconciled: bool}
     */
    public function cogsSchedule(FiscalYear $fiscalYear, string $from, string $to): array
    {
        $glFrom = max($from, $fiscalYear->start_date->toDateString());

        $openRm = $this->openingValue(self::CLASS_RAW_MATERIAL, $from);
        $openWip = $this->openingValue(self::CLASS_WIP, $from);
        $openFg = $this->openingValue(self::CLASS_FINISHED_GOOD, $from);

        $closeRm = PerpetualCosting::classValue(InventoryClass::RawMaterial, $to);
        $closeWip = WipLedger::balance(null, $to);
        $closeFg = PerpetualCosting::classValue(InventoryClass::FinishedGood, $to);

        $rm = $this->costByType(InventoryClass::RawMaterial, $from, $to);
        $fg = $this->costByType(InventoryClass::FinishedGood, $from, $to);

        $rmPurchases = $this->purchasesNet($rm);
        $rmAdjustmentLoss = $this->adjustmentLoss($rm);
        $rmSoldDirectly = $this->soldNet($rm);
        $fgPurchases = $this->purchasesNet($fg);
        $fgAdjustmentLoss = $this->adjustmentLoss($fg);

        $directCosts = $this->wipKindTotal(ProductionWipEntry::KIND_DIRECT_COST, $from, $to);
        $productionLoss = $this->wipKindTotal(ProductionWipEntry::KIND_ABANDON, $from, $to);

        $rmConsumed = $openRm->plus($rmPurchases)->minus($closeRm)->minus($rmAdjustmentLoss)->minus($rmSoldDirectly);
        $totalManufacturing = $rmConsumed->plus($directCosts);
        $cogm = $totalManufacturing->plus($openWip)->minus($closeWip)->minus($productionLoss);
        $cogs = $openFg->plus($fgPurchases)->plus($cogm)->minus($closeFg)->minus($fgAdjustmentLoss)->plus($rmSoldDirectly);

        $glCogs = $this->glBalance($fiscalYear, ManufacturingAccounts::COGS, $glFrom, $to, true);
        $difference = $glCogs->minus($cogs);

        $lines = [
            $this->line('opening_rm', 'Opening raw material', $openRm),
            $this->line('rm_purchases', 'Add: raw material purchases (net of returns)', $rmPurchases),
            $this->line('closing_rm', 'Less: closing raw material', $closeRm),
            $this->line('rm_adjustments', 'Less: raw material adjustments (net loss)', $rmAdjustmentLoss),
            $this->line('rm_sold_directly', 'Less: raw material sold directly (charged to COGS at sale)', $rmSoldDirectly),
            $this->line('rm_consumed', 'Raw material consumed', $rmConsumed, 'subtotal'),
            $this->line('direct_costs', 'Add: direct production costs', $directCosts),
            $this->line('total_manufacturing', 'Total manufacturing cost', $totalManufacturing, 'subtotal'),
            $this->line('opening_wip', 'Add: opening work in process', $openWip),
            $this->line('closing_wip', 'Less: closing work in process', $closeWip),
            $this->line('production_loss', 'Less: production loss (abandoned WIP)', $productionLoss),
            $this->line('cogm', 'Cost of goods manufactured', $cogm, 'subtotal'),
            $this->line('opening_fg', 'Add: opening finished goods', $openFg),
            $this->line('fg_purchases', 'Add: finished goods purchased (net of returns)', $fgPurchases),
            $this->line('closing_fg', 'Less: closing finished goods', $closeFg),
            $this->line('fg_adjustments', 'Less: finished goods adjustments (net loss)', $fgAdjustmentLoss),
            $this->line('rm_sold_directly_cogs', 'Add: raw material sold directly', $rmSoldDirectly),
            $this->line('computed_cogs', 'Cost of goods sold (computed)', $cogs, 'total'),
            $this->line('gl_cogs', 'Cost of goods sold per ledger (EXE10)', $glCogs, 'total'),
            $this->line('reconciling', 'Reconciling difference (ledger less computed)', $difference, $difference->isZero() ? 'subtotal' : 'alert'),
        ];

        return [
            'from' => $from,
            'to' => $to,
            'fiscalYear' => $fiscalYear->bs_label,
            'lines' => $lines,
            'computedCogs' => $cogs->toString(),
            'glCogs' => $glCogs->toString(),
            'difference' => $difference->toString(),
            'reconciled' => $difference->isZero(),
        ];
    }

    /**
     * The MC9 reconciliation, reshaped to strings for the page: per class the
     * GL balance, the subledger total and the difference.
     *
     * @return array{asOf: string, fiscalYear: ?string, rows: array<int, array{key: string, label: string, gl: string, subledger: string, difference: string}>, balanced: bool}
     */
    public function reconciliation(FiscalYear $fiscalYear, string $asOf): array
    {
        $check = InventoryReconciliation::check($fiscalYear, $asOf);

        $rows = [];
        $balanced = true;

        foreach (self::classKeys() as $key) {
            $entry = $check[$key];
            $balanced = $balanced && $entry['difference']->isZero();

            $rows[] = [
                'key' => $key,
                'label' => self::classLabel($key),
                'gl' => $entry['gl']->toString(),
                'subledger' => $entry['subledger']->toString(),
                'difference' => $entry['difference']->toString(),
            ];
        }

        return ['asOf' => $asOf, 'fiscalYear' => $fiscalYear->bs_label, 'rows' => $rows, 'balanced' => $balanced];
    }

    /**
     * Net GL balance (debit less credit) of one account inside one fiscal
     * year. `$excludeClosing` drops the year-end sweep vouchers, which is what
     * a period figure for a profit-and-loss account needs: after close() the
     * sweep would otherwise net EXE10 to zero.
     */
    public function glBalance(FiscalYear $fiscalYear, string $code, ?string $from, string $to, bool $excludeClosing): Money
    {
        $accountId = Account::where('code', $code)->value('id');

        if ($accountId === null) {
            return Money::zero();
        }

        $cast = $this->integerCast();

        $row = JournalVoucherLine::query()
            ->where('account_id', $accountId)
            ->whereHas('journalVoucher', function (Builder $voucher) use ($fiscalYear, $from, $to, $excludeClosing) {
                $voucher->where('fiscal_year_id', $fiscalYear->id)
                    ->when($from !== null, fn (Builder $q) => $q->whereDate('date', '>=', $from))
                    ->whereDate('date', '<=', $to)
                    ->when($excludeClosing, fn (Builder $q) => $q->where('voucher_type', '!=', VoucherType::ClosingEntry->value));
            })
            ->selectRaw(
                "COALESCE(SUM(CAST(ROUND(debit * 100) AS {$cast})), 0) as debit_scaled,
                 COALESCE(SUM(CAST(ROUND(credit * 100) AS {$cast})), 0) as credit_scaled"
            )
            ->first();

        return $this->fromScaled($row?->debit_scaled ?? 0, 100)
            ->minus($this->fromScaled($row?->credit_scaled ?? 0, 100));
    }

    /**
     * @return array{class: string, label: string, from: string, to: string, openingFromSnapshot: bool, opening: array{quantity: ?string, value: string}, rows: array<int, array<string, mixed>>, closing: array{quantity: ?string, value: string}}
     */
    private function movementLedger(InventoryClass $class, string $from, string $to, ?int $itemId): array
    {
        $dayBefore = Carbon::parse($from)->subDay()->toDateString();

        $snapshot = $this->snapshotOpening($class->value, $from, $itemId);

        if ($snapshot !== null) {
            [$openQuantity, $openValue] = $snapshot;
        } else {
            [$openQuantity, $openValue] = $this->movementTotals($class, null, $dayBefore, $itemId);
        }

        $movements = ItemStockMovement::query()
            ->join('items', 'items.id', '=', 'item_stock_movements.item_id')
            ->where('items.inventory_class', $class->value)
            ->where('item_stock_movements.cancelled', false)
            ->whereDate('item_stock_movements.date', '>=', $from)
            ->whereDate('item_stock_movements.date', '<=', $to)
            ->when($itemId !== null, fn (Builder $q) => $q->where('item_stock_movements.item_id', $itemId))
            ->select('item_stock_movements.*')
            ->with(['item:id,name,unit', 'store:id,name'])
            ->with($this->stockMovementReferenceEagerLoad())
            ->orderBy('item_stock_movements.date')
            ->orderBy('item_stock_movements.id')
            ->get();

        $quantity = $openQuantity;
        $value = $openValue;
        $rows = [];

        foreach ($movements as $movement) {
            $isIn = $movement->movement_type->direction() === 1;
            $moved = Quantity::of($movement->quantity);
            $cost = Money::round((string) ($movement->cost_value ?? '0'));

            $quantity = $isIn ? $quantity->plus($moved) : $quantity->minus($moved);
            $value = $isIn ? $value->plus($cost) : $value->minus($cost);

            $rows[] = [
                'date' => $movement->date->toDateString(),
                'document' => $this->movementTypeLabel($movement->movement_type).' · '.($movement->item?->name ?? '-')
                    .' · '.$this->referenceDescription($movement->reference, $movement->narration),
                'inQuantity' => $isIn ? $moved->toString() : null,
                'outQuantity' => $isIn ? null : $moved->toString(),
                'balanceQuantity' => $quantity->toString(),
                'inValue' => $isIn ? $cost->toString() : null,
                'outValue' => $isIn ? null : $cost->toString(),
                'balanceValue' => $value->toString(),
            ];
        }

        return [
            'class' => $class->value,
            'label' => self::classLabel($class->value),
            'from' => $from,
            'to' => $to,
            'openingFromSnapshot' => $snapshot !== null,
            'opening' => ['quantity' => $openQuantity->toString(), 'value' => $openValue->toString()],
            'rows' => $rows,
            'closing' => ['quantity' => $quantity->toString(), 'value' => $value->toString()],
        ];
    }

    /**
     * @return array{class: string, label: string, from: string, to: string, openingFromSnapshot: bool, opening: array{quantity: ?string, value: string}, rows: array<int, array<string, mixed>>, closing: array{quantity: ?string, value: string}}
     */
    private function wipLedger(string $from, string $to): array
    {
        $dayBefore = Carbon::parse($from)->subDay()->toDateString();

        $snapshot = $this->snapshotOpening(self::CLASS_WIP, $from, null);
        $openValue = $snapshot !== null ? $snapshot[1] : WipLedger::balance(null, $dayBefore);

        $entries = ProductionWipEntry::query()
            ->where('cancelled', false)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->with(['item:id,name', 'productionEntry:id,number', 'materialIssueNote:id,number'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $value = $openValue;
        $rows = [];

        foreach ($entries as $entry) {
            $isIn = $entry->direction === 1;
            $amount = Money::round((string) $entry->value);
            $value = $isIn ? $value->plus($amount) : $value->minus($amount);

            $reference = $entry->productionEntry?->number ?? $entry->materialIssueNote?->number ?? '-';
            $quantity = $entry->quantity === null ? null : Quantity::of($entry->quantity)->toString();

            $rows[] = [
                'date' => $entry->date->toDateString(),
                'document' => $this->wipKindLabel($entry->kind).' · '.$reference.($entry->item ? ' · '.$entry->item->name : ''),
                'inQuantity' => $isIn ? $quantity : null,
                'outQuantity' => $isIn ? null : $quantity,
                'balanceQuantity' => null,
                'inValue' => $isIn ? $amount->toString() : null,
                'outValue' => $isIn ? null : $amount->toString(),
                'balanceValue' => $value->toString(),
            ];
        }

        return [
            'class' => self::CLASS_WIP,
            'label' => self::classLabel(self::CLASS_WIP),
            'from' => $from,
            'to' => $to,
            'openingFromSnapshot' => $snapshot !== null,
            'opening' => ['quantity' => null, 'value' => $openValue->toString()],
            'rows' => $rows,
            'closing' => ['quantity' => null, 'value' => $value->toString()],
        ];
    }

    private function wipKindLabel(string $kind): string
    {
        return match ($kind) {
            ProductionWipEntry::KIND_MATERIAL_IN => 'Material issued',
            ProductionWipEntry::KIND_MATERIAL_OUT => 'Material returned',
            ProductionWipEntry::KIND_DIRECT_COST => 'Direct cost',
            ProductionWipEntry::KIND_COMPLETION => 'Completion',
            ProductionWipEntry::KIND_ABANDON => 'Abandoned (production loss)',
            default => $kind,
        };
    }

    /**
     * Opening figure for a class on `$from`: the prior year's verified
     * snapshot when the window starts the day after that year ended, else the
     * balance as of the day before.
     */
    private function openingValue(string $class, string $from): Money
    {
        $snapshot = $this->snapshotOpening($class, $from, null);

        if ($snapshot !== null) {
            return $snapshot[1];
        }

        $dayBefore = Carbon::parse($from)->subDay()->toDateString();

        return match ($class) {
            self::CLASS_WIP => WipLedger::balance(null, $dayBefore),
            default => PerpetualCosting::classValue(InventoryClass::from($class), $dayBefore),
        };
    }

    /**
     * The snapshot rows of the fiscal year that ended the day before `$from`,
     * summed; null when there is no such year or it wrote no rows for the
     * class (a year that closed with none of it holds nothing to carry).
     *
     * @return array{0: Quantity, 1: Money}|null
     */
    private function snapshotOpening(string $class, string $from, ?int $itemId): ?array
    {
        $dayBefore = Carbon::parse($from)->subDay()->toDateString();
        $prior = FiscalYear::query()->whereDate('end_date', $dayBefore)->first();

        if ($prior === null) {
            return null;
        }

        $rows = FiscalYearInventorySnapshot::query()
            ->where('fiscal_year_id', $prior->id)
            ->where('inventory_class', $class)
            ->when($itemId !== null, fn (Builder $q) => $q->where('item_id', $itemId))
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        return [
            Quantity::sum($rows->map(fn (FiscalYearInventorySnapshot $row) => Quantity::of((string) ($row->quantity ?? '0')))),
            Money::sum($rows->map(fn (FiscalYearInventorySnapshot $row) => Money::round((string) $row->value))),
        ];
    }

    /**
     * Signed quantity and cost balance of one class's movements in a window.
     *
     * @return array{0: Quantity, 1: Money}
     */
    private function movementTotals(InventoryClass $class, ?string $from, string $to, ?int $itemId): array
    {
        $cast = $this->integerCast();
        $inTypes = $this->directionTypes(1);
        $placeholders = implode(',', array_fill(0, count($inTypes), '?'));
        $quantity = "CAST(ROUND(item_stock_movements.quantity * 10000) AS {$cast})";
        $cost = "CAST(ROUND(COALESCE(item_stock_movements.cost_value, 0) * 100) AS {$cast})";

        $row = ItemStockMovement::query()
            ->join('items', 'items.id', '=', 'item_stock_movements.item_id')
            ->where('items.inventory_class', $class->value)
            ->where('item_stock_movements.cancelled', false)
            ->when($from !== null, fn (Builder $q) => $q->whereDate('item_stock_movements.date', '>=', $from))
            ->whereDate('item_stock_movements.date', '<=', $to)
            ->when($itemId !== null, fn (Builder $q) => $q->where('item_stock_movements.item_id', $itemId))
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN item_stock_movements.movement_type IN ({$placeholders}) THEN {$quantity} ELSE -{$quantity} END), 0) as quantity_scaled,
                 COALESCE(SUM(CASE WHEN item_stock_movements.movement_type IN ({$placeholders}) THEN {$cost} ELSE -{$cost} END), 0) as cost_scaled",
                [...$inTypes, ...$inTypes],
            )
            ->first();

        return [
            Quantity::of($this->scaledDecimal($row?->quantity_scaled ?? 0, 10000)),
            $this->fromScaled($row?->cost_scaled ?? 0, 100),
        ];
    }

    /**
     * Unsigned cost_value per movement type for one class in a window.
     *
     * @return array<string, Money>
     */
    private function costByType(InventoryClass $class, string $from, string $to): array
    {
        $cast = $this->integerCast();

        $rows = ItemStockMovement::query()
            ->join('items', 'items.id', '=', 'item_stock_movements.item_id')
            ->where('items.inventory_class', $class->value)
            ->where('item_stock_movements.cancelled', false)
            ->whereDate('item_stock_movements.date', '>=', $from)
            ->whereDate('item_stock_movements.date', '<=', $to)
            ->selectRaw("item_stock_movements.movement_type as movement_type, COALESCE(SUM(CAST(ROUND(COALESCE(item_stock_movements.cost_value, 0) * 100) AS {$cast})), 0) as cost_scaled")
            ->groupBy('item_stock_movements.movement_type')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(string) $row->movement_type] = $this->fromScaled($row->cost_scaled, 100);
        }

        return $totals;
    }

    /**
     * @param  array<string, Money>  $byType
     */
    private function typeCost(array $byType, StockMovementType $type): Money
    {
        return $byType[$type->value] ?? Money::zero();
    }

    /**
     * Purchases and opening-stock imports, net of purchase returns.
     *
     * @param  array<string, Money>  $byType
     */
    private function purchasesNet(array $byType): Money
    {
        return $this->typeCost($byType, StockMovementType::Purchase)
            ->plus($this->typeCost($byType, StockMovementType::Opening))
            ->minus($this->typeCost($byType, StockMovementType::PurchaseReturn));
    }

    /**
     * Net adjustment loss: adjustments out less adjustments in.
     *
     * @param  array<string, Money>  $byType
     */
    private function adjustmentLoss(array $byType): Money
    {
        return $this->typeCost($byType, StockMovementType::AdjustmentOut)
            ->minus($this->typeCost($byType, StockMovementType::AdjustmentIn));
    }

    /**
     * Cost of items sold, net of sale returns.
     *
     * @param  array<string, Money>  $byType
     */
    private function soldNet(array $byType): Money
    {
        return $this->typeCost($byType, StockMovementType::Sale)
            ->minus($this->typeCost($byType, StockMovementType::SaleReturn));
    }

    private function wipKindTotal(string $kind, string $from, string $to): Money
    {
        $cast = $this->integerCast();

        $scaled = ProductionWipEntry::query()
            ->where('cancelled', false)
            ->where('kind', $kind)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->selectRaw("COALESCE(SUM(CAST(ROUND(value * 100) AS {$cast})), 0) as value_scaled")
            ->value('value_scaled');

        return $this->fromScaled($scaled ?? 0, 100);
    }

    /**
     * Trading stock for the schedule. After close() has posted the year-end
     * pair (a ClosingEntry voucher touching AS11) the ledger holds the
     * closing value; before that the Balance Sheet shows the live periodic
     * closing value, so this does too.
     */
    private function tradingStock(FiscalYear $fiscalYear, string $asOf): Money
    {
        $stockInHand = Account::where('code', FiscalYear::STOCK_IN_HAND_CODE)->first();

        if ($stockInHand === null) {
            return Money::zero();
        }

        $posted = JournalVoucher::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('voucher_type', VoucherType::ClosingEntry->value)
            ->whereHas('lines', fn (Builder $lines) => $lines->where('account_id', $stockInHand->id))
            ->exists();

        return $posted
            ? $this->glBalance($fiscalYear, FiscalYear::STOCK_IN_HAND_CODE, null, $asOf, false)
            : StockCosting::totalPeriodicClosingValue($asOf);
    }

    /**
     * @return array<int, string>
     */
    private function directionTypes(int $direction): array
    {
        return array_values(array_map(
            fn (StockMovementType $type) => $type->value,
            array_filter(StockMovementType::cases(), fn (StockMovementType $type) => $type->direction() === $direction),
        ));
    }

    /**
     * @return array{key: string, label: string, amount: string, style: string}
     */
    private function line(string $key, string $label, Money $amount, string $style = 'line'): array
    {
        return ['key' => $key, 'label' => $label, 'amount' => $amount->toString(), 'style' => $style];
    }

    private function integerCast(): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';
    }

    /**
     * A scaled-integer SQL sum (value x 100 for money) back to an exact Money.
     */
    private function fromScaled(int|string $scaled, int $divisor): Money
    {
        return Money::of($this->scaledDecimal($scaled, $divisor));
    }

    /**
     * Exact division by a power of ten, at that power's number of decimals.
     */
    private function scaledDecimal(int|string $scaled, int $divisor): BigDecimal
    {
        return BigDecimal::of((string) (int) $scaled)
            ->dividedBy($divisor, strlen((string) $divisor) - 1, RoundingMode::Unnecessary);
    }
}
