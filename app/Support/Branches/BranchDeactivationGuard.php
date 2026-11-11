<?php

namespace App\Support\Branches;

use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\CapitalPurchase;
use App\Models\FixedAsset;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\ProductionEntry;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\StaffMember;
use App\Models\Store;
use App\Models\User;
use App\Support\Hr\StaffBalances;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * What still ties a branch to work that must be finished inside it, so the
 * branch cannot be deactivated yet (plan 12, BR10, carry-forwards BR09 (a)
 * and BR07 (e)).
 *
 * The posting engine refuses an inactive branch on every posted line and the
 * I6 allocation checks refuse inactive branches too, so anything left open in
 * a deactivated branch would dead-end, even for the owner: an invoice could
 * never be receipted, a payslip never paid, an advance never recovered, an
 * asset never depreciated or disposed, and stock in its stores never moved.
 * Deactivation is therefore refused until each listed item is cleared, and
 * there is deliberately no payroll-only or settle-only bypass (if one is ever
 * wanted it is one engine option shared by receipts, payments and payroll).
 *
 * Every read runs inside BranchContext::unrestricted(): the answer is a fact
 * about the branch, not about the branches the asking admin may see. Read
 * only, so the BR23 `branches:audit` command can reuse blockers() as is.
 *
 * Money is compared through Money, quantities as scaled integers in SQL; no
 * float ever decides whether something is outstanding.
 */
final class BranchDeactivationGuard
{
    /**
     * How many example document numbers each blocker names.
     */
    public const EXAMPLE_LIMIT = 5;

    /**
     * Rows read per batch when outstanding amounts must be computed per
     * document (deactivation is rare, so this favours memory over speed).
     */
    private const CHUNK = 200;

    /**
     * The permission a viewer needs to see a blocker's example numbers or
     * names. Counts are always shown; examples only to someone who could
     * open those records anyway.
     *
     * @var array<string, string>
     */
    private const EXAMPLE_PERMISSIONS = [
        'unpaid_sales' => 'sales.view',
        'unpaid_purchases' => 'purchases.view',
        'unpaid_capital_purchases' => 'capital_purchases.view',
        'unpaid_payslips' => 'payroll.view',
        'outstanding_advances' => 'staff_advances.view',
        'active_staff' => 'hr_staff.view',
        'active_fixed_assets' => 'fixed_assets.view',
        'open_quotations' => 'quotations.view',
        'pending_sales_returns' => 'sales_returns.view',
        'production_in_progress' => 'production_entries.view',
        'draft_payroll_runs' => 'payroll.view',
        'transfers_in_transit' => 'stock_transfers.view',
        'stock_on_hand' => 'items.view',
    ];

    /**
     * Every reason the branch cannot be deactivated right now, in a fixed
     * order. Empty means it can be.
     *
     * @return list<array{key: string, label: string, count: int, examples: list<string>, action: string}>
     */
    public static function blockers(Branch $branch): array
    {
        $branchId = (int) $branch->getKey();

        return BranchContext::unrestricted(function () use ($branchId): array {
            $checks = [
                self::unpaidSales($branchId),
                self::unpaidPurchases($branchId),
                self::unpaidCapitalPurchases($branchId),
                self::unpaidPayslips($branchId),
                self::outstandingAdvances($branchId),
                self::activeStaff($branchId),
                self::activeFixedAssets($branchId),
                self::openQuotations($branchId),
                self::pendingSalesReturns($branchId),
                self::productionInProgress($branchId),
                self::draftPayrollRuns($branchId),
                self::transfersInTransit($branchId),
                self::stockOnHand($branchId),
            ];

            return array_values(array_filter($checks, fn (?array $blocker): bool => $blocker !== null));
        });
    }

    /**
     * One plain sentence per blocker, for a validation error. With a $viewer,
     * the examples of a blocker are left out unless the viewer holds its
     * view permission (EXAMPLE_PERMISSIONS); the count is always given.
     *
     * @param  list<array{key: string, label: string, count: int, examples: list<string>, action: string}>  $blockers
     */
    public static function message(Branch $branch, array $blockers, ?User $viewer = null): string
    {
        $parts = array_map(static function (array $blocker) use ($viewer): string {
            $permission = self::EXAMPLE_PERMISSIONS[$blocker['key']] ?? null;
            $mayList = $viewer === null || ($permission !== null && $viewer->hasPermission($permission));
            $examples = ! $mayList || $blocker['examples'] === [] ? '' : ' ('.implode(', ', $blocker['examples'])
                .($blocker['count'] > count($blocker['examples']) ? ', ...' : '').')';

            return "{$blocker['count']} {$blocker['label']}{$examples}: {$blocker['action']}.";
        }, $blockers);

        return "\"{$branch->name}\" cannot be deactivated yet. Clear these first: ".implode(' ', $parts);
    }

    /**
     * @return array{key: string, label: string, count: int, examples: list<string>, action: string}|null
     */
    private static function unpaidSales(int $branchId): ?array
    {
        $count = 0;
        $examples = [];

        // Only credit invoices can be owed: a cash or bank sale settles its
        // whole due at posting (Sale::outstandingAmount()). Shortlisted in SQL
        // to invoices whose due (total less TDS) exceeds their live receipt
        // allocations, summed as integer paisa so no float decides it. Returns
        // can only lower what is owed further, so this is a superset of the
        // owed invoices; outstandingAmount() then decides each one exactly.
        $allocated = 'SELECT COALESCE(SUM('.self::paisa('receipt_allocations.amount').'), 0) FROM receipt_allocations'
            .' INNER JOIN receipts ON receipts.id = receipt_allocations.receipt_id'
            ." WHERE receipt_allocations.sale_id = sales.id AND receipts.status <> 'cancelled'";

        Sale::query()
            ->where('branch_id', $branchId)
            ->where('status', 'posted')
            ->where('payment_mode', 'credit')
            ->whereRaw(self::paisa('sales.total').' - '.self::paisa('COALESCE(sales.tds_amount, 0)')." > ({$allocated})")
            ->chunkById(self::CHUNK, function (EloquentCollection $sales) use (&$count, &$examples): void {
                foreach ($sales as $sale) {
                    if ($sale->outstandingAmount()->isPositive()) {
                        $count++;
                        self::addExample($examples, (string) ($sale->invoice_number ?? "#{$sale->id}"));
                    }
                }
            });

        return self::blocker('unpaid_sales', 'unpaid sales invoice(s)', $count, $examples, 'record the receipts or a sales return');
    }

    /**
     * @return array{key: string, label: string, count: int, examples: list<string>, action: string}|null
     */
    private static function unpaidPurchases(int $branchId): ?array
    {
        $count = 0;
        $examples = [];

        Purchase::query()
            ->where('branch_id', $branchId)
            ->where('status', 'posted')
            ->chunkById(self::CHUNK, function (EloquentCollection $purchases) use (&$count, &$examples): void {
                $outstanding = Purchase::outstandingAmounts($purchases);

                foreach ($purchases as $purchase) {
                    if (($outstanding[$purchase->id] ?? Money::zero())->isPositive()) {
                        $count++;
                        self::addExample($examples, (string) ($purchase->bill_number ?: "#{$purchase->id}"));
                    }
                }
            });

        return self::blocker('unpaid_purchases', 'unpaid purchase bill(s)', $count, $examples, 'record the payments or a purchase return');
    }

    /**
     * @return array{key: string, label: string, count: int, examples: list<string>, action: string}|null
     */
    private static function unpaidCapitalPurchases(int $branchId): ?array
    {
        $count = 0;
        $examples = [];

        // Cash and bank bills owe nothing (CapitalPurchase::initialUnpaidAmount()).
        // Shortlisted in SQL with the same rule as outstandingAmount() (unpaid
        // at posting less posted settlements, in integer paisa), which then
        // confirms each one.
        $unpaidAtPosting = "CASE WHEN capital_purchases.payment_mode = 'credit' THEN ".self::paisa('capital_purchases.total')
            .' ELSE '.self::paisa('capital_purchases.total').' - '.self::paisa('COALESCE(capital_purchases.cash_amount, 0)')
            .' - '.self::paisa('COALESCE(capital_purchases.bank_amount, 0)').' END';
        $settled = 'SELECT COALESCE(SUM('.self::paisa('capital_purchase_settlements.amount').'), 0) FROM capital_purchase_settlements'
            ." WHERE capital_purchase_settlements.capital_purchase_id = capital_purchases.id AND capital_purchase_settlements.status = 'posted'";

        CapitalPurchase::query()
            ->where('branch_id', $branchId)
            ->where('status', '!=', 'cancelled')
            ->whereIn('payment_mode', ['credit', 'partial'])
            ->whereRaw("({$unpaidAtPosting}) > ({$settled})")
            ->chunkById(self::CHUNK, function (EloquentCollection $bills) use (&$count, &$examples): void {
                foreach ($bills as $bill) {
                    if ($bill->outstandingAmount()->isPositive()) {
                        $count++;
                        self::addExample($examples, (string) ($bill->bill_number ?: "#{$bill->id}"));
                    }
                }
            });

        return self::blocker('unpaid_capital_purchases', 'capital purchase bill(s) with a balance to settle', $count, $examples, 'record the settlements');
    }

    /**
     * Posted payslips filed in this branch with net pay still unpaid. Draft
     * runs are reported separately (draftPayrollRuns()).
     *
     * @return array{key: string, label: string, count: int, examples: list<string>, action: string}|null
     */
    private static function unpaidPayslips(int $branchId): ?array
    {
        $count = 0;
        $examples = [];

        PayrollLine::query()
            ->where('branch_id', $branchId)
            ->whereHas('run', fn ($run) => $run->where('status', PayrollRun::STATUS_POSTED))
            ->with(['run:id,run_number,series_branch_id', 'staff:id,name'])
            ->chunkById(self::CHUNK, function (EloquentCollection $lines) use (&$count, &$examples): void {
                $outstanding = PayrollLine::outstandingAmounts($lines);

                foreach ($lines as $line) {
                    if (($outstanding[$line->id] ?? Money::zero())->isPositive()) {
                        $count++;
                        $run = $line->run?->displayNumber() ?? "run #{$line->payroll_run_id}";
                        self::addExample($examples, trim(($line->staff?->name ?? '').' '.$run));
                    }
                }
            });

        return self::blocker('unpaid_payslips', 'unpaid payslip(s)', $count, $examples, 'pay them through Salary payments');
    }

    /**
     * Staff whose home branch is this one and who still owe an advance. A
     * staff member cannot change branch while an advance is outstanding, so
     * the receivable sits in their home branch.
     *
     * @return array{key: string, label: string, count: int, examples: list<string>, action: string}|null
     */
    private static function outstandingAdvances(int $branchId): ?array
    {
        $owing = array_filter(StaffBalances::advances(), fn (Money $balance): bool => $balance->isPositive());

        if ($owing === []) {
            return null;
        }

        $staff = StaffMember::query()
            ->where('branch_id', $branchId)
            ->whereIn('id', array_keys($owing))
            ->orderBy('name')
            ->pluck('name');

        $examples = [];

        foreach ($staff as $name) {
            self::addExample($examples, (string) $name);
        }

        return self::blocker('outstanding_advances', 'staff member(s) with an outstanding advance', $staff->count(), $examples, 'recover the advances through payroll or cancel them');
    }

    /**
     * Active staff based in this branch: their next payslip would post into
     * it. Someone whose employment has already ended no longer counts.
     *
     * @return array{key: string, label: string, count: int, examples: list<string>, action: string}|null
     */
    private static function activeStaff(int $branchId): ?array
    {
        $today = now()->toDateString();
        $query = StaffMember::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->where(fn ($staff) => $staff->whereNull('end_date')->orWhereDate('end_date', '>', $today));

        return self::blocker(
            'active_staff',
            'active staff member(s) based here',
            (clone $query)->count(),
            $query->orderBy('name')->limit(self::EXAMPLE_LIMIT)->pluck('name')->map(fn ($name): string => (string) $name)->all(),
            'move them to another branch or mark them inactive',
        );
    }

    /**
     * @return array{key: string, label: string, count: int, examples: list<string>, action: string}|null
     */
    private static function activeFixedAssets(int $branchId): ?array
    {
        $query = FixedAsset::query()->where('branch_id', $branchId)->where('status', 'active');

        return self::blocker(
            'active_fixed_assets',
            'active fixed asset(s)',
            (clone $query)->count(),
            $query->orderBy('id')->limit(self::EXAMPLE_LIMIT)->pluck('asset_code')->map(fn ($code): string => (string) $code)->all(),
            'dispose of them (asset transfer between branches is not supported yet)',
        );
    }

    /**
     * @return array{key: string, label: string, count: int, examples: list<string>, action: string}|null
     */
    private static function openQuotations(int $branchId): ?array
    {
        $query = Quotation::query()->where('branch_id', $branchId)->where('status', 'draft');

        return self::blocker(
            'open_quotations',
            'open quotation(s)',
            (clone $query)->count(),
            $query->orderBy('id')->limit(self::EXAMPLE_LIMIT)->pluck('id')->map(fn ($id): string => "#{$id}")->all(),
            'convert or cancel them',
        );
    }

    /**
     * @return array{key: string, label: string, count: int, examples: list<string>, action: string}|null
     */
    private static function pendingSalesReturns(int $branchId): ?array
    {
        $query = SalesReturn::query()->where('branch_id', $branchId)->where('status', 'pending');

        return self::blocker(
            'pending_sales_returns',
            'pending sales return request(s)',
            (clone $query)->count(),
            $query->orderBy('id')->limit(self::EXAMPLE_LIMIT)->pluck('id')->map(fn ($id): string => "#{$id}")->all(),
            'approve or reject them',
        );
    }

    /**
     * @return array{key: string, label: string, count: int, examples: list<string>, action: string}|null
     */
    private static function productionInProgress(int $branchId): ?array
    {
        $query = ProductionEntry::query()->where('branch_id', $branchId)->where('status', ProductionEntry::STATUS_IN_PROGRESS);

        return self::blocker(
            'production_in_progress',
            'production entr(ies) still in progress',
            (clone $query)->count(),
            $query->orderBy('id')->limit(self::EXAMPLE_LIMIT)->pluck('number')->map(fn ($number): string => "#{$number}")->all(),
            'complete or abandon them',
        );
    }

    /**
     * Draft runs that would post into this branch: filed in it, or holding a
     * payslip of a staff member based in it.
     *
     * @return array{key: string, label: string, count: int, examples: list<string>, action: string}|null
     */
    private static function draftPayrollRuns(int $branchId): ?array
    {
        $query = PayrollRun::query()
            ->where('status', PayrollRun::STATUS_DRAFT)
            ->where(fn ($run) => $run->where('branch_id', $branchId)
                ->orWhereHas('lines', fn ($line) => $line->where('branch_id', $branchId)));

        return self::blocker(
            'draft_payroll_runs',
            'draft payroll run(s)',
            (clone $query)->count(),
            $query->orderBy('id')->limit(self::EXAMPLE_LIMIT)->pluck('period_label')->map(fn ($label): string => (string) $label)->all(),
            'post or discard them',
        );
    }

    /**
     * Transfers dispatched but not yet received at either end (status
     * in_transit, StockTransfer::STATUS_IN_TRANSIT, BR18): the receiving
     * branch could never post its receipt voucher once inactive, and the
     * sending branch could never post the cancellation's reversal.
     *
     * @return array{key: string, label: string, count: int, examples: list<string>, action: string}|null
     */
    private static function transfersInTransit(int $branchId): ?array
    {
        $query = DB::table('stock_transfers')
            ->where('status', 'in_transit')
            ->where(fn ($transfer) => $transfer->where('from_branch_id', $branchId)->orWhere('to_branch_id', $branchId));

        return self::blocker(
            'transfers_in_transit',
            'stock transfer(s) in transit',
            (clone $query)->count(),
            $query->orderBy('id')->limit(self::EXAMPLE_LIMIT)->pluck('id')->map(fn ($id): string => "#{$id}")->all(),
            'receive or cancel them',
        );
    }

    /**
     * Items with a non-zero balance (negative stock included) in any store of
     * the branch. Summed as scaled integers in SQL, the same way
     * Item::currentStockByItem() does, so no float decides the answer.
     *
     * @return array{key: string, label: string, count: int, examples: list<string>, action: string}|null
     */
    private static function stockOnHand(int $branchId): ?array
    {
        $storeIds = Store::query()->where('branch_id', $branchId)->pluck('id')->all();

        if ($storeIds === []) {
            return null;
        }

        $cast = DB::connection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';
        $scaled = "CAST(ROUND(item_stock_movements.quantity * 10000) AS {$cast})";
        $inTypes = array_values(array_map(
            fn (StockMovementType $type): string => $type->value,
            array_filter(StockMovementType::cases(), fn (StockMovementType $type): bool => $type->direction() === 1),
        ));
        $placeholders = implode(', ', array_fill(0, count($inTypes), '?'));

        $rows = DB::table('item_stock_movements')
            ->join('items', 'items.id', '=', 'item_stock_movements.item_id')
            ->whereIn('item_stock_movements.store_id', $storeIds)
            ->where('item_stock_movements.cancelled', false)
            ->groupBy('item_stock_movements.item_id', 'items.name')
            ->havingRaw("SUM(CASE WHEN item_stock_movements.movement_type IN ({$placeholders}) THEN {$scaled} ELSE -{$scaled} END) <> 0", $inTypes)
            ->orderBy('items.name')
            ->pluck('items.name');

        $examples = [];

        foreach ($rows as $name) {
            self::addExample($examples, (string) $name);
        }

        return self::blocker('stock_on_hand', 'item(s) with stock in this branch\'s stores', $rows->count(), $examples, 'sell it, adjust it to zero, or transfer it to another branch');
    }

    /**
     * A money column as whole paisa, the same exact scaled-integer trick
     * Item::currentStockByItem() uses for quantities: each 2-decimal value is
     * rounded to an integer before summing, so REAL affinity on SQLite cannot
     * tip a comparison.
     */
    private static function paisa(string $expression): string
    {
        $cast = DB::connection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        return "CAST(ROUND({$expression} * 100) AS {$cast})";
    }

    /**
     * @param  list<string>  $examples
     * @return array{key: string, label: string, count: int, examples: list<string>, action: string}|null
     */
    private static function blocker(string $key, string $label, int $count, array $examples, string $action): ?array
    {
        if ($count === 0) {
            return null;
        }

        return ['key' => $key, 'label' => $label, 'count' => $count, 'examples' => $examples, 'action' => $action];
    }

    /**
     * @param  list<string>  $examples
     */
    private static function addExample(array &$examples, string $example): void
    {
        if (count($examples) < self::EXAMPLE_LIMIT && $example !== '') {
            $examples[] = $example;
        }
    }
}
