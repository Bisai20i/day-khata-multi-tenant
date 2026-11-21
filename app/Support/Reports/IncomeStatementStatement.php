<?php

namespace App\Support\Reports;

use App\Models\FiscalYear;
use App\Support\Money\Money;

/**
 * The legacy-style vertical income statement (old reportsController::
 * newIncomestatement): revenue less returns, cost of sales built from
 * opening stock + net purchases - closing stock, gross profit, then the
 * indirect income and expenses down to net profit.
 *
 * Pure and DB-free: it only re-arranges the income / expense rows the
 * income statement has already computed, so it cannot disagree with them.
 * Every row is placed by the same trading codes grossProfit() uses - an
 * income row whose code is a sales code (Sales Accounts + INI22) sits in
 * revenue or closing stock, an expense row whose code is a purchase code
 * (Purchase Accounts + Direct Expenses + EXE9) in cost of sales or opening
 * stock, everything else below the gross profit line. The Direct Expenses
 * accounts (`$tradingCodes['direct']`, plus any codeless row whose `group`
 * is "Direct Expenses", see isCodelessDirectExpense()) are shown as their
 * own block inside cost of sales, never among the purchases. That partition
 * makes, by construction:
 *
 * - grossProfit === sum(trading income rows) - sum(trading expense rows),
 *   exactly what AccountingReportController::grossProfit() returns;
 * - costOfSales.total === openingStock + (purchases - purchaseReturn)
 *   + directExpenses - closingStock;
 * - netProfit === totalIncome - totalExpenses.
 *
 * Sales return (INI21) and purchase return (EXE81) are shown as positive
 * figures that are subtracted (a debit balance on an income account is a
 * negative income row, so the row is negated). Rows sharing a trimmed,
 * case-insensitive name within one section are merged into one (legacy
 * groupAccountsByName).
 *
 * Every flat row list has a parallel nested list next to it (`salesGroups`,
 * `salesReturnGroups`, `purchaseGroups`, `purchaseReturnGroups`,
 * `directExpenseGroups`, `otherIncome.groups`, `expenses.groups`): the same
 * rows (already merged, returns already negated) arranged under their
 * account group and subgroup, with exact Money::sum totals. The group totals
 * of a section therefore add up to that section's own total string, to the
 * paisa. Group order is first appearance in the flat list, row order inside
 * a node is the flat order; a row with no group sits under "Ungrouped".
 * The stock rows are not grouped.
 *
 * @phpstan-type Row array{id: ?int, code: ?string, name: string, amount: string, computed: bool, merged: bool, by_branch: array<int, string>|null, group: ?string, subgroup: ?string}
 * @phpstan-type SubgroupNode array{name: string, total: string, by_branch: array<int, string>|null, rows: list<Row>}
 * @phpstan-type GroupNode array{name: string, total: string, by_branch: array<int, string>|null, rows: list<Row>, subgroups: list<SubgroupNode>}
 * @phpstan-type Statement array{
 *     revenue: array{salesRows: list<Row>, salesReturnRows: list<Row>, salesGroups: list<GroupNode>, salesReturnGroups: list<GroupNode>, sales: string, salesReturn: string, net: string},
 *     costOfSales: array{openingStock: Row, purchaseRows: list<Row>, purchaseReturnRows: list<Row>, directExpenseRows: list<Row>, purchaseGroups: list<GroupNode>, purchaseReturnGroups: list<GroupNode>, directExpenseGroups: list<GroupNode>, purchases: string, purchaseReturn: string, directExpenses: string, closingStock: Row, total: string},
 *     grossProfit: string,
 *     otherIncome: array{rows: list<Row>, groups: list<GroupNode>, total: string},
 *     expenses: array{rows: list<Row>, groups: list<GroupNode>, total: string},
 *     netProfit: string,
 * }
 */
final class IncomeStatementStatement
{
    public const SALES_RETURN_CODE = 'INI21';

    public const PURCHASE_RETURN_CODE = 'EXE81';

    /**
     * The expense group whose accounts are cost of sales (above gross
     * profit). A protected group name (AccountGroup::PROTECTED_NAMES).
     */
    public const DIRECT_EXPENSES_GROUP = 'Direct Expenses';

    /**
     * Group node name for rows with no account group (virtual rows).
     */
    public const UNGROUPED = 'Ungrouped';

    /**
     * @param  array<int, array{id: ?int, code: ?string, name: string, amount: string, computed: bool, by_branch?: array<int, string>, group?: ?string, subgroup?: ?string}>  $income  Income rows, credit-normal (positive when earned).
     * @param  array<int, array{id: ?int, code: ?string, name: string, amount: string, computed: bool, by_branch?: array<int, string>, group?: ?string, subgroup?: ?string}>  $expenses  Expense rows, debit-normal (positive when spent).
     * @param  array{sales: list<string>, purchases: list<string>, direct?: list<string>}  $tradingCodes  AccountingReportController::tradingCodes(); `direct` defaults to none.
     * @param  list<int>|null  $branchIds  The by-branch column ids, or null when the statement has no branch columns.
     * @return Statement
     */
    public static function build(array $income, array $expenses, array $tradingCodes, ?array $branchIds = null): array
    {
        $salesCodes = [...$tradingCodes['sales'], FiscalYear::CLOSING_STOCK_CODE];
        $purchaseCodes = [...$tradingCodes['purchases'], FiscalYear::OPENING_STOCK_CODE];
        $directCodes = $tradingCodes['direct'] ?? [];

        $salesRows = [];
        $salesReturnRows = [];
        $closingStockRows = [];
        $otherIncomeRows = [];

        foreach ($income as $row) {
            $row = self::normalise($row);

            if (! self::hasCode($row, $salesCodes)) {
                $otherIncomeRows[] = $row;
            } elseif ($row['code'] === FiscalYear::CLOSING_STOCK_CODE) {
                $closingStockRows[] = $row;
            } elseif ($row['code'] === self::SALES_RETURN_CODE) {
                $salesReturnRows[] = self::negate($row);
            } else {
                $salesRows[] = $row;
            }
        }

        $purchaseRows = [];
        $purchaseReturnRows = [];
        $directExpenseRows = [];
        $openingStockRows = [];
        $expenseRows = [];

        foreach ($expenses as $row) {
            $row = self::normalise($row);

            // Opening stock first, as before; then the direct block (its
            // codes are also in the purchase list, so it must be checked
            // before the purchase rows); a codeless Direct Expenses row is
            // trading too, exactly as grossProfit() counts it.
            if ($row['code'] === FiscalYear::OPENING_STOCK_CODE) {
                $openingStockRows[] = $row;
            } elseif (self::hasCode($row, $directCodes) || self::isCodelessDirectExpense($row)) {
                $directExpenseRows[] = $row;
            } elseif (! self::hasCode($row, $purchaseCodes)) {
                $expenseRows[] = $row;
            } elseif ($row['code'] === self::PURCHASE_RETURN_CODE) {
                $purchaseReturnRows[] = self::negate($row);
            } else {
                $purchaseRows[] = $row;
            }
        }

        $salesRows = self::mergeByName($salesRows);
        $salesReturnRows = self::mergeByName($salesReturnRows);
        $purchaseRows = self::mergeByName($purchaseRows);
        $purchaseReturnRows = self::mergeByName($purchaseReturnRows);
        $directExpenseRows = self::mergeByName($directExpenseRows);
        $otherIncomeRows = self::mergeByName($otherIncomeRows);
        $expenseRows = self::mergeByName($expenseRows);

        $openingStock = self::stockRow($openingStockRows, FiscalYear::OPENING_STOCK_CODE, 'Opening Stock', $branchIds);
        $closingStock = self::stockRow($closingStockRows, FiscalYear::CLOSING_STOCK_CODE, 'Closing Stock', $branchIds);

        $sales = self::total($salesRows);
        $salesReturn = self::total($salesReturnRows);
        $revenueNet = $sales->minus($salesReturn);

        $purchases = self::total($purchaseRows);
        $purchaseReturn = self::total($purchaseReturnRows);
        $directExpenses = self::total($directExpenseRows);
        $costOfSales = Money::of($openingStock['amount'])
            ->plus($purchases->minus($purchaseReturn))
            ->plus($directExpenses)
            ->minus($closingStock['amount']);

        $grossProfit = $revenueNet->minus($costOfSales);
        $otherIncome = self::total($otherIncomeRows);
        $otherExpenses = self::total($expenseRows);

        return [
            'revenue' => [
                'salesRows' => $salesRows,
                'salesReturnRows' => $salesReturnRows,
                'salesGroups' => self::groups($salesRows),
                'salesReturnGroups' => self::groups($salesReturnRows),
                'sales' => $sales->toString(),
                'salesReturn' => $salesReturn->toString(),
                'net' => $revenueNet->toString(),
            ],
            'costOfSales' => [
                'openingStock' => $openingStock,
                'purchaseRows' => $purchaseRows,
                'purchaseReturnRows' => $purchaseReturnRows,
                'directExpenseRows' => $directExpenseRows,
                'purchaseGroups' => self::groups($purchaseRows),
                'purchaseReturnGroups' => self::groups($purchaseReturnRows),
                'directExpenseGroups' => self::groups($directExpenseRows),
                'purchases' => $purchases->toString(),
                'purchaseReturn' => $purchaseReturn->toString(),
                'directExpenses' => $directExpenses->toString(),
                'closingStock' => $closingStock,
                'total' => $costOfSales->toString(),
            ],
            'grossProfit' => $grossProfit->toString(),
            'otherIncome' => [
                'rows' => $otherIncomeRows,
                'groups' => self::groups($otherIncomeRows),
                'total' => $otherIncome->toString(),
            ],
            'expenses' => [
                'rows' => $expenseRows,
                'groups' => self::groups($expenseRows),
                'total' => $otherExpenses->toString(),
            ],
            'netProfit' => $grossProfit->plus($otherIncome)->minus($otherExpenses)->toString(),
        ];
    }

    /**
     * An expense row with no account code that belongs to the Direct
     * Expenses group. Account codes are optional on tenant-created accounts,
     * so a code-only partition would silently drop such an account below
     * gross profit; AccountingReportController::grossProfit() (and every
     * consumer that splits the trading section) adds these rows to the
     * trading expenses through this one test, keyed on the `group` that
     * headBalances() puts on each row.
     *
     * @param  array{code: ?string, group?: ?string}  $row
     */
    public static function isCodelessDirectExpense(array $row): bool
    {
        return ($row['code'] === null || $row['code'] === '')
            && ($row['group'] ?? null) === self::DIRECT_EXPENSES_GROUP;
    }

    /**
     * @param  array{id: ?int, code: ?string, name: string, amount: string, computed: bool, by_branch?: array<int, string>|null, group?: ?string, subgroup?: ?string}  $row
     * @return Row
     */
    private static function normalise(array $row): array
    {
        return [
            'id' => $row['id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'amount' => Money::of($row['amount'])->toString(),
            'computed' => $row['computed'],
            'merged' => false,
            'by_branch' => $row['by_branch'] ?? null,
            'group' => $row['group'] ?? null,
            'subgroup' => $row['subgroup'] ?? null,
        ];
    }

    /**
     * The rows of one section nested under their account group and
     * subgroup. Totals are exact Money::sum rollups of the given rows, so
     * the group totals add up to the section's own total. A row with a
     * subgroup goes under that subgroup of its group, a row without one
     * directly under the group. Groups and subgroups appear in the order of
     * their first row; rows keep the flat order.
     *
     * @param  list<Row>  $rows
     * @return list<GroupNode>
     */
    private static function groups(array $rows): array
    {
        /** @var array<string, array{name: string, rows: list<Row>, subgroups: array<string, list<Row>>, all: list<Row>}> $nodes */
        $nodes = [];

        foreach ($rows as $row) {
            // Keyed apart from real names so a null group never collides
            // with a group that happens to be called "Ungrouped".
            $groupKey = $row['group'] === null ? "\0" : 'g:'.$row['group'];

            if (! isset($nodes[$groupKey])) {
                $nodes[$groupKey] = [
                    'name' => $row['group'] ?? self::UNGROUPED,
                    'rows' => [],
                    'subgroups' => [],
                    'all' => [],
                ];
            }

            $nodes[$groupKey]['all'][] = $row;

            if ($row['subgroup'] === null) {
                $nodes[$groupKey]['rows'][] = $row;
            } else {
                $nodes[$groupKey]['subgroups'][$row['subgroup']][] = $row;
            }
        }

        $groups = [];

        foreach ($nodes as $node) {
            $subgroups = [];

            foreach ($node['subgroups'] as $name => $subgroupRows) {
                $subgroups[] = [
                    'name' => (string) $name,
                    'total' => self::total($subgroupRows)->toString(),
                    'by_branch' => self::branchTotals($subgroupRows),
                    'rows' => $subgroupRows,
                ];
            }

            $groups[] = [
                'name' => $node['name'],
                'total' => self::total($node['all'])->toString(),
                'by_branch' => self::branchTotals($node['all']),
                'rows' => $node['rows'],
                'subgroups' => $subgroups,
            ];
        }

        return $groups;
    }

    /**
     * Per-branch sums of the rows' `by_branch`, or null when no row has
     * branch amounts (a statement without branch columns).
     *
     * @param  list<Row>  $rows
     * @return array<int, string>|null
     */
    private static function branchTotals(array $rows): ?array
    {
        $amounts = null;

        foreach ($rows as $row) {
            if ($row['by_branch'] === null) {
                continue;
            }

            foreach ($row['by_branch'] as $branchId => $amount) {
                $amounts[$branchId][] = $amount;
            }
        }

        if ($amounts === null) {
            return null;
        }

        return array_map(fn (array $branchAmounts): string => Money::sum($branchAmounts)->toString(), $amounts);
    }

    /**
     * @param  Row  $row
     * @param  list<string>  $codes
     */
    private static function hasCode(array $row, array $codes): bool
    {
        return $row['code'] !== null && in_array($row['code'], $codes, true);
    }

    /**
     * A return row flipped to a positive "subtracted" figure.
     *
     * @param  Row  $row
     * @return Row
     */
    private static function negate(array $row): array
    {
        $row['amount'] = Money::of($row['amount'])->negated()->toString();

        if ($row['by_branch'] !== null) {
            $row['by_branch'] = array_map(fn (string $amount): string => Money::of($amount)->negated()->toString(), $row['by_branch']);
        }

        return $row;
    }

    /**
     * Rows with the same trimmed, case-insensitive name become one row: the
     * first row's id, code, name, group and subgroup, the amounts (and per-branch amounts)
     * summed. Order follows each name's first appearance. When the rows come
     * from more than one distinct account id the merged amount belongs to no
     * single ledger, so the row gets `id => null` and `merged => true` (the
     * page then shows it unlinked); the same id repeated, or null ids, keep
     * the first row's id.
     *
     * @param  list<Row>  $rows
     * @return list<Row>
     */
    private static function mergeByName(array $rows): array
    {
        $merged = [];
        $accountIds = [];

        foreach ($rows as $row) {
            $key = mb_strtolower(trim($row['name']));

            if (! isset($merged[$key])) {
                $merged[$key] = $row;
                $accountIds[$key] = $row['id'] === null ? [] : [$row['id'] => true];

                continue;
            }

            $merged[$key] = self::combine($merged[$key], $row);

            if ($row['id'] !== null) {
                $accountIds[$key][$row['id']] = true;
            }

            if (count($accountIds[$key]) > 1) {
                $merged[$key]['id'] = null;
                $merged[$key]['merged'] = true;
            }
        }

        return array_values($merged);
    }

    /**
     * @param  Row  $into
     * @param  Row  $row
     * @return Row
     */
    private static function combine(array $into, array $row): array
    {
        $into['amount'] = Money::of($into['amount'])->plus($row['amount'])->toString();

        if ($into['by_branch'] !== null || $row['by_branch'] !== null) {
            $byBranch = $into['by_branch'] ?? [];

            foreach ($row['by_branch'] ?? [] as $branchId => $amount) {
                $byBranch[$branchId] = Money::of($byBranch[$branchId] ?? '0')->plus($amount)->toString();
            }

            $into['by_branch'] = $byBranch;
        }

        return $into;
    }

    /**
     * The opening or closing stock line, always present (0.00 when there is
     * no stock). Real EXE9 / INI22 balances (a year whose trading entries are
     * posted) and the virtual rows of an unposted year both land here. A
     * computed line (no id, so no link) unless every contributing row is a
     * real ledger row; then it is not computed, and carries the account id
     * when exactly one real account contributed so the page can link to it.
     *
     * @param  list<Row>  $rows
     * @param  list<int>|null  $branchIds
     * @return Row
     */
    private static function stockRow(array $rows, string $code, string $name, ?array $branchIds): array
    {
        $stock = [
            'id' => null,
            'code' => $code,
            'name' => $name,
            'amount' => Money::zero()->toString(),
            'computed' => true,
            'merged' => false,
            'by_branch' => $branchIds === null
                ? null
                : array_fill_keys($branchIds, Money::zero()->toString()),
            'group' => null,
            'subgroup' => null,
        ];

        $realIds = [];
        $allReal = $rows !== [];

        foreach ($rows as $row) {
            $stock = self::combine($stock, $row);

            if ($row['id'] !== null && ! $row['computed']) {
                $realIds[$row['id']] = true;
            } else {
                $allReal = false;
            }
        }

        $stock['id'] = $allReal && count($realIds) === 1 ? array_key_first($realIds) : null;
        $stock['code'] = $code;
        $stock['name'] = $name;
        $stock['computed'] = ! $allReal;
        $stock['merged'] = false;

        return $stock;
    }

    /**
     * @param  list<Row>  $rows
     */
    private static function total(array $rows): Money
    {
        return Money::sum(array_column($rows, 'amount'));
    }
}
