<?php

namespace App\Support\Reports;

use App\Support\Money\Money;

/**
 * The legacy-style trial balance: Head -> Group -> Sub-group -> Ledger with
 * opening / period / closing debit and credit totals per sub-group, group and
 * head, heads printed Assets, Liabilities, Capital, Income, Expenses.
 *
 * Pure and DB-free: it only decorates the hierarchy that
 * AccountingReportController::buildHierarchy() already produced. Every key
 * it adds is additive - ledger arrays are returned unchanged (only their
 * order within a group/sub-group is name-sorted), nothing is renamed. Rows
 * are only removed when a `level` above `ledger` is asked for (see build()).
 *
 * @phpstan-type Totals array{openingDebit: string, openingCredit: string, periodDebit: string, periodCredit: string, closingDebit: string, closingCredit: string}
 * @phpstan-type Account array<string, mixed>
 * @phpstan-type Subgroup array{name: string, accounts: list<Account>, totals: Totals, by_branch?: array<int, string>}
 * @phpstan-type Group array{name: string, accounts: list<Account>, subgroups: list<Subgroup>, totals: Totals, by_branch?: array<int, string>}
 * @phpstan-type Head array{name: string, groups: list<Group>, totals: Totals, by_branch?: array<int, string>}
 */
final class TrialBalanceStatement
{
    /** Heads printed first, in this order; any other head follows in its original order. */
    private const LEADING_HEADS = ['Assets', 'Liabilities', 'Capital', 'Income', 'Expenses'];

    /** The account keys that are totalled at every level. */
    private const TOTAL_KEYS = ['openingDebit', 'openingCredit', 'periodDebit', 'periodCredit', 'closingDebit', 'closingCredit'];

    /**
     * @param  array<int, array{name: string, groups: array<int, array{name: string, accounts: array<int, array<string, mixed>>, subgroups?: array<int, array{name: string, accounts: array<int, array<string, mixed>>}>}>}>  $heads  buildHierarchy() output.
     * @param  list<int>|null  $branchIds  The by-branch column ids, or null when the report has no branch columns.
     * @param  string  $unclassifiedName  The head that collects unmapped accounts; always printed last.
     * @param  string  $level  Lowest level of rows kept: `ledger` (default, every row), `subgroup`
     *                         (each sub-group's `accounts` emptied; ledgers sitting directly under a
     *                         group, with no sub-group, stay so a group total reconciles with its
     *                         visible rows) or `group` (each group's `accounts` and `subgroups`
     *                         emptied). Any other value acts as `ledger`. Totals and `by_branch` are
     *                         always computed from the full hierarchy first, then rows are dropped,
     *                         so every head and group total is identical at every level.
     * @return list<Head>
     */
    public static function build(array $heads, ?array $branchIds = null, string $unclassifiedName = 'Unclassified', string $level = 'ledger'): array
    {
        $built = [];

        foreach ($heads as $head) {
            $groups = array_map(
                fn (array $group): array => self::group($group, $branchIds),
                self::sortedByName(array_values($head['groups'] ?? [])),
            );

            $head['totals'] = self::sumTotals(array_column($groups, 'totals'));

            if ($branchIds !== null) {
                $head['by_branch'] = self::sumBranches($groups, $branchIds);
            }

            $head['groups'] = array_map(fn (array $group): array => self::pruned($group, $level), $groups);

            $built[] = $head;
        }

        return self::ordered($built, $unclassifiedName);
    }

    /**
     * The grand total of already-built heads (sum of each head's `totals`).
     *
     * @param  array<int, array{totals: array<string, string>}>  $heads  build() output.
     * @return Totals
     */
    public static function grand(array $heads): array
    {
        return self::sumTotals(array_column($heads, 'totals'));
    }

    /**
     * @param  array{name: string, accounts: array<int, array<string, mixed>>, subgroups?: array<int, array{name: string, accounts: array<int, array<string, mixed>>}>}  $group
     * @param  list<int>|null  $branchIds
     * @return Group
     */
    private static function group(array $group, ?array $branchIds): array
    {
        $group['accounts'] = self::sortedByName(array_values($group['accounts'] ?? []));
        $group['subgroups'] = array_map(function (array $subgroup) use ($branchIds): array {
            $subgroup['accounts'] = self::sortedByName(array_values($subgroup['accounts'] ?? []));
            $subgroup['totals'] = self::sumAccounts($subgroup['accounts']);

            if ($branchIds !== null) {
                $subgroup['by_branch'] = self::sumBranches($subgroup['accounts'], $branchIds);
            }

            return $subgroup;
        }, self::sortedByName(array_values($group['subgroups'] ?? [])));

        $group['totals'] = self::sumTotals([
            self::sumAccounts($group['accounts']),
            ...array_column($group['subgroups'], 'totals'),
        ]);

        if ($branchIds !== null) {
            $direct = ['by_branch' => self::sumBranches($group['accounts'], $branchIds)];
            $group['by_branch'] = self::sumBranches([$direct, ...$group['subgroups']], $branchIds);
        }

        return $group;
    }

    /**
     * Drops the rows below $level from an already-totalled group. Totals and
     * `by_branch` are left untouched.
     *
     * @param  Group  $group
     * @return Group
     */
    private static function pruned(array $group, string $level): array
    {
        if ($level === 'group') {
            $group['accounts'] = [];
            $group['subgroups'] = [];
        } elseif ($level === 'subgroup') {
            $group['subgroups'] = array_map(function (array $subgroup): array {
                $subgroup['accounts'] = [];

                return $subgroup;
            }, $group['subgroups']);
        }

        return $group;
    }

    /**
     * Exact per-key totals of ledger rows; a key a row lacks counts zero.
     *
     * @param  array<int, array<string, mixed>>  $accounts
     * @return Totals
     */
    private static function sumAccounts(array $accounts): array
    {
        $totals = [];

        foreach (self::TOTAL_KEYS as $key) {
            $totals[$key] = Money::sum(array_map(fn (array $account): string => (string) ($account[$key] ?? '0'), $accounts))->toString();
        }

        return $totals;
    }

    /**
     * Exact per-key sum of already-totalled `totals` arrays.
     *
     * @param  array<int, array<string, string>>  $parts
     * @return Totals
     */
    private static function sumTotals(array $parts): array
    {
        $totals = [];

        foreach (self::TOTAL_KEYS as $key) {
            $totals[$key] = Money::sum(array_map(fn (array $part): string => $part[$key] ?? '0', $parts))->toString();
        }

        return $totals;
    }

    /**
     * Per-branch sums of rows' `by_branch`; a branch a row lacks counts zero.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  list<int>  $branchIds
     * @return array<int, string>
     */
    private static function sumBranches(array $rows, array $branchIds): array
    {
        $sums = [];

        foreach ($branchIds as $branchId) {
            $sums[$branchId] = Money::sum(array_map(fn (array $row): string => (string) ($row['by_branch'][$branchId] ?? '0'), $rows))->toString();
        }

        return $sums;
    }

    /**
     * Case-insensitive natural order by `name`, ties kept in original order.
     *
     * @template T of array
     *
     * @param  list<T>  $rows
     * @return list<T>
     */
    private static function sortedByName(array $rows): array
    {
        $indexed = array_keys($rows);
        usort($indexed, fn (int $a, int $b): int => strnatcasecmp((string) ($rows[$a]['name'] ?? ''), (string) ($rows[$b]['name'] ?? '')) ?: $a <=> $b);

        return array_map(fn (int $index): array => $rows[$index], $indexed);
    }

    /**
     * Assets, Liabilities, Capital, Income, Expenses, then other heads in
     * original order, then the unclassified head last.
     *
     * @param  list<Head>  $heads
     * @return list<Head>
     */
    private static function ordered(array $heads, string $unclassifiedName): array
    {
        $other = count(self::LEADING_HEADS);

        $rank = function (array $head) use ($unclassifiedName, $other): int {
            $leading = array_search($head['name'], self::LEADING_HEADS, true);

            return match (true) {
                $leading !== false => $leading,
                $head['name'] === $unclassifiedName => $other + 1,
                default => $other,
            };
        };

        $indexed = array_keys($heads);
        usort($indexed, fn (int $a, int $b): int => [$rank($heads[$a]), $a] <=> [$rank($heads[$b]), $b]);

        return array_map(fn (int $index): array => $heads[$index], $indexed);
    }
}
