<?php

namespace App\Support\Reports;

use App\Support\Money\Money;

/**
 * The legacy-style balance sheet (old reportsController::newBalancesheet):
 * Head -> Group -> Sub-group -> Ledger, with a signed amount per ledger and
 * a total per sub-group, group and head, Capital listed before Liabilities.
 *
 * Pure and DB-free: it only decorates the hierarchy that
 * AccountingReportController::buildHierarchy() already produced. Every key
 * it adds is additive; nothing existing is renamed, and nodes are removed
 * only by the optional zero pruning below.
 *
 * A ledger's `amount` is in statement sign: debit - credit under Assets,
 * credit - debit under every other head. So an asset with a credit balance
 * (an overdraft) shows negative and reduces the Assets total instead of
 * disappearing, and likewise a liability with a debit balance.
 *
 * Groups inside a head are ordered Current before Non-Current (Current
 * Assets, then Fixed Assets; Current Liabilities, then Non-Current
 * Liabilities); every other group follows in its original order.
 *
 * Current year profit / (loss), when given, is one synthetic ledger row
 * (`id` null, `synthetic` true) appended to the Capital head's first
 * group's direct accounts, BEFORE any total is computed, so the group,
 * the Capital head and their `by_branch` include it. A "Capital Account"
 * group (and the Capital head) is created only when none exists.
 *
 * With $hideZero, after every total is computed, a ledger whose `amount`
 * and every `by_branch` entry are zero is dropped, then every sub-group,
 * group and head left without a ledger. Totals are never recomputed:
 * a zero row adds nothing to them.
 *
 * @phpstan-type Account array{id: ?int, code: ?string, name: string, name_np?: ?string, debit?: string, credit?: string, by_branch?: array<int, string>, amount: string, synthetic?: bool}
 * @phpstan-type Subgroup array{name: string, accounts: list<Account>, total: string, by_branch?: array<int, string>}
 * @phpstan-type Group array{name: string, accounts: list<Account>, subgroups: list<Subgroup>, total: string, by_branch?: array<int, string>}
 * @phpstan-type Head array{name: string, groups: list<Group>, total: string, by_branch?: array<int, string>}
 */
final class BalanceSheetStatement
{
    public const ASSETS = 'Assets';

    public const CAPITAL = 'Capital';

    /** Heads printed first, in this order; any other head follows in its original order. */
    private const LEADING_HEADS = [self::ASSETS, self::CAPITAL, 'Liabilities'];

    /** Group created under Capital to hold the profit row when Capital has no group. */
    public const CAPITAL_GROUP = 'Capital Account';

    public const CURRENT_YEAR_PROFIT_LABEL = 'Current Year Profit / (Loss)';

    /** Groups printed first inside their head, in this order; any other group follows in its original order. */
    private const LEADING_GROUPS = ['Current Assets', 'Current Liabilities', 'Fixed Assets', 'Non-Current Liabilities'];

    /**
     * @param  array<int, array{name: string, groups: array<int, array{name: string, accounts: array<int, array<string, mixed>>, subgroups: array<int, array{name: string, accounts: array<int, array<string, mixed>>}>}>}>  $heads  buildHierarchy() output.
     * @param  list<int>|null  $branchIds  The by-branch column ids, or null when the sheet has no branch columns.
     * @param  string  $unclassifiedName  The head that collects unmapped accounts; always printed last.
     * @param  array{amount: string, by_branch: array<int, string>|null}|null  $currentYearProfit  Unswept profit in statement sign (credit minus debit, profit positive); null adds no row.
     * @param  bool  $hideZero  Prune zero ledgers and the nodes they leave empty, after totals.
     * @return list<Head>
     */
    public static function build(array $heads, ?array $branchIds = null, string $unclassifiedName = 'Unclassified', ?array $currentYearProfit = null, bool $hideZero = false): array
    {
        $built = [];

        if ($currentYearProfit !== null) {
            $heads = self::withProfitRow(array_values($heads), $currentYearProfit, $branchIds);
        }

        foreach ($heads as $head) {
            $isAssets = $head['name'] === self::ASSETS;
            $head['groups'] = array_map(fn (array $group): array => self::group($group, $isAssets, $branchIds), self::orderedGroups(array_values($head['groups'])));

            $built[] = self::withTotal($head, $head['groups'], $branchIds);
        }

        $built = self::ordered($built, $unclassifiedName);

        return $hideZero ? self::pruned($built) : $built;
    }

    /**
     * Appends the synthetic profit row to the Capital head's first group
     * (after group ordering), creating the head and a "Capital Account"
     * group when missing.
     *
     * @param  list<array<string, mixed>>  $heads
     * @param  array{amount: string, by_branch: array<int, string>|null}  $profit
     * @param  list<int>|null  $branchIds
     * @return list<array<string, mixed>>
     */
    private static function withProfitRow(array $heads, array $profit, ?array $branchIds): array
    {
        $row = [
            'id' => null,
            'code' => null,
            'name' => self::CURRENT_YEAR_PROFIT_LABEL,
            'name_np' => null,
            'amount' => Money::of($profit['amount'])->toString(),
            'synthetic' => true,
        ];

        if ($branchIds !== null) {
            $row['by_branch'] = [];

            foreach ($branchIds as $branchId) {
                $row['by_branch'][$branchId] = Money::of($profit['by_branch'][$branchId] ?? '0')->toString();
            }
        }

        $capitalIndex = null;

        foreach ($heads as $index => $head) {
            if ($head['name'] === self::CAPITAL) {
                $capitalIndex = $index;
                break;
            }
        }

        if ($capitalIndex === null) {
            $heads[] = ['name' => self::CAPITAL, 'groups' => []];
            $capitalIndex = array_key_last($heads);
        }

        $groups = self::orderedGroups(array_values($heads[$capitalIndex]['groups']));

        if ($groups === []) {
            $groups[] = ['name' => self::CAPITAL_GROUP, 'accounts' => [], 'subgroups' => []];
        }

        $groups[0]['accounts'] = [...array_values($groups[0]['accounts'] ?? []), $row];
        $heads[$capitalIndex]['groups'] = $groups;

        return $heads;
    }

    /**
     * Current Assets / Current Liabilities first, then Fixed Assets /
     * Non-Current Liabilities, then every other group in original order.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private static function orderedGroups(array $groups): array
    {
        $rank = function (array $group): int {
            $leading = array_search($group['name'], self::LEADING_GROUPS, true);

            return $leading === false ? count(self::LEADING_GROUPS) : $leading;
        };

        $indexed = array_keys($groups);
        usort($indexed, fn (int $a, int $b): int => [$rank($groups[$a]), $a] <=> [$rank($groups[$b]), $b]);

        return array_map(fn (int $index): array => $groups[$index], $indexed);
    }

    /**
     * Drops zero ledgers, then sub-groups, groups and heads left without a
     * ledger. Totals stay as computed.
     *
     * @param  list<Head>  $heads
     * @return list<Head>
     */
    private static function pruned(array $heads): array
    {
        $keepAccounts = fn (array $accounts): array => array_values(array_filter($accounts, fn (array $account): bool => ! self::isZeroLedger($account)));

        $result = [];

        foreach ($heads as $head) {
            $groups = [];

            foreach ($head['groups'] as $group) {
                $group['accounts'] = $keepAccounts($group['accounts']);
                $subgroups = [];

                foreach ($group['subgroups'] as $subgroup) {
                    $subgroup['accounts'] = $keepAccounts($subgroup['accounts']);

                    if ($subgroup['accounts'] !== []) {
                        $subgroups[] = $subgroup;
                    }
                }

                $group['subgroups'] = $subgroups;

                if ($group['accounts'] !== [] || $subgroups !== []) {
                    $groups[] = $group;
                }
            }

            if ($groups !== []) {
                $head['groups'] = $groups;
                $result[] = $head;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private static function isZeroLedger(array $account): bool
    {
        if (! Money::of($account['amount'])->isZero()) {
            return false;
        }

        foreach ($account['by_branch'] ?? [] as $amount) {
            if (! Money::of($amount)->isZero()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{name: string, accounts: array<int, array<string, mixed>>, subgroups: array<int, array{name: string, accounts: array<int, array<string, mixed>>}>}  $group
     * @param  list<int>|null  $branchIds
     * @return Group
     */
    private static function group(array $group, bool $isAssets, ?array $branchIds): array
    {
        $group['accounts'] = self::accounts($group['accounts'], $isAssets);
        $group['subgroups'] = array_map(function (array $subgroup) use ($isAssets, $branchIds): array {
            $subgroup['accounts'] = self::accounts($subgroup['accounts'], $isAssets);

            return self::withTotal($subgroup, [['total' => self::sumAmounts($subgroup['accounts']), 'by_branch' => self::sumBranches($subgroup['accounts'], $branchIds)]], $branchIds);
        }, array_values($group['subgroups'] ?? []));

        $parts = [
            ['total' => self::sumAmounts($group['accounts']), 'by_branch' => self::sumBranches($group['accounts'], $branchIds)],
            ...$group['subgroups'],
        ];

        return self::withTotal($group, $parts, $branchIds);
    }

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     * @return list<Account>
     */
    private static function accounts(array $accounts, bool $isAssets): array
    {
        return array_map(function (array $account) use ($isAssets): array {
            // The profit row already carries its statement-sign amount.
            if (($account['synthetic'] ?? false) === true) {
                return $account;
            }

            $debit = Money::of($account['debit'] ?? '0');
            $credit = Money::of($account['credit'] ?? '0');
            $account['amount'] = ($isAssets ? $debit->minus($credit) : $credit->minus($debit))->toString();

            return $account;
        }, array_values($accounts));
    }

    /**
     * Adds `total` (and `by_branch` when there are branch columns) to a node
     * from its already-totalled parts.
     *
     * @template T of array
     *
     * @param  T  $node
     * @param  array<int, array{total: string, by_branch?: array<int, string>|null}>  $parts
     * @param  list<int>|null  $branchIds
     * @return T
     */
    private static function withTotal(array $node, array $parts, ?array $branchIds): array
    {
        $node['total'] = Money::sum(array_column($parts, 'total'))->toString();

        if ($branchIds !== null) {
            $node['by_branch'] = self::sumBranches($parts, $branchIds);
        }

        return $node;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function sumAmounts(array $rows): string
    {
        return Money::sum(array_column($rows, 'amount'))->toString();
    }

    /**
     * Per-branch sums of rows' `by_branch`; a branch a row lacks counts zero.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  list<int>|null  $branchIds
     * @return array<int, string>|null
     */
    private static function sumBranches(array $rows, ?array $branchIds): ?array
    {
        if ($branchIds === null) {
            return null;
        }

        $sums = [];

        foreach ($branchIds as $branchId) {
            $sums[$branchId] = Money::sum(array_map(fn (array $row): string => $row['by_branch'][$branchId] ?? '0', $rows))->toString();
        }

        return $sums;
    }

    /**
     * Assets, Capital, Liabilities, then other heads in original order, then
     * the unclassified head last.
     *
     * @param  list<Head>  $heads
     * @return list<Head>
     */
    private static function ordered(array $heads, string $unclassifiedName): array
    {
        $rank = function (array $head) use ($unclassifiedName): int {
            $leading = array_search($head['name'], self::LEADING_HEADS, true);

            return match (true) {
                $leading !== false => $leading,
                $head['name'] === $unclassifiedName => 4,
                default => 3,
            };
        };

        $indexed = array_keys($heads);
        usort($indexed, fn (int $a, int $b): int => [$rank($heads[$a]), $a] <=> [$rank($heads[$b]), $b]);

        return array_map(fn (int $index): array => $heads[$index], $indexed);
    }
}
