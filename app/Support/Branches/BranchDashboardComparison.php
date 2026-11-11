<?php

namespace App\Support\Branches;

use App\Models\Purchase;
use App\Models\Sale;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

/**
 * The dashboard's branch comparison widget (plan 12, BR16): this month's
 * sales and purchases side by side for each branch the dashboard covers.
 *
 * Cheap on purpose: ONE grouped query per figure (GROUP BY the document's
 * branch_id), never a query per branch, and nothing at all for a tenant that
 * is not multi-branch or a dashboard narrowed to a single branch. The full
 * picture (gross and net profit per branch, which needs a per-branch closing
 * stock valuation) is the branch comparison report's job, not the
 * dashboard's.
 *
 * Follows the dashboard's BranchFilter: the branches compared are
 * BranchFilter::columns(), so a restricted user only ever sees their own
 * branches, and the document queries carry applyToDocuments() on top of
 * BranchAccessScope.
 */
final class BranchDashboardComparison
{
    /**
     * The `branchComparison` prop, or null when there is nothing to compare
     * (not multi-branch, fewer than two branches covered, or neither figure
     * allowed).
     *
     * @param  string  $since  First day of the period (Y-m-d).
     * @param  bool  $canOpenReport  Whether the user may open the branch comparison report (branches.report).
     * @return array{since: string, showSales: bool, showPurchases: bool, canOpenReport: bool, branches: list<array{id: int, name: string, code: string, sales: string|null, purchases: string|null}>}|null
     */
    public static function build(BranchFilter $filter, string $since, bool $withSales, bool $withPurchases, bool $canOpenReport): ?array
    {
        if (! $filter->isActive() || $filter->isSingle() || count($filter->options()) < 2) {
            return null;
        }

        if (! $withSales && ! $withPurchases) {
            return null;
        }

        $sales = $withSales ? self::totalsByBranch(Sale::query(), $filter, $since) : [];
        $purchases = $withPurchases ? self::totalsByBranch(Purchase::query(), $filter, $since) : [];

        // Inactive branches only get a row when they still have documents
        // this month, so the rows always add up to the covered branches' total.
        $columns = $filter->columns(array_values(array_unique([...array_keys($sales), ...array_keys($purchases)])));

        if (count($columns) < 2) {
            return null;
        }

        return [
            'since' => $since,
            'showSales' => $withSales,
            'showPurchases' => $withPurchases,
            'canOpenReport' => $canOpenReport,
            'branches' => array_map(fn (array $column): array => [
                'id' => $column['id'],
                'name' => $column['name'],
                'code' => $column['code'],
                'sales' => $withSales ? ($sales[$column['id']] ?? Money::zero())->toString() : null,
                'purchases' => $withPurchases ? ($purchases[$column['id']] ?? Money::zero())->toString() : null,
            ], $columns),
        ];
    }

    /**
     * Non-cancelled document totals since $since, per document branch, in
     * one grouped query. Scaled-integer SUM: SQLite reads a decimal column
     * back as REAL, so a plain SUM() would be a float sum.
     *
     * @param  Builder<Sale>|Builder<Purchase>  $documents
     * @return array<int, Money> branch id => total
     */
    private static function totalsByBranch(Builder $documents, BranchFilter $filter, string $since): array
    {
        $cast = $documents->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        $documents
            ->where('status', '!=', 'cancelled')
            ->where('date', '>=', $since);

        $rows = $filter->applyToDocuments($documents)
            ->groupBy('branch_id')
            ->selectRaw("branch_id, COALESCE(SUM(CAST(ROUND(total * 100) AS {$cast})), 0) as total_scaled")
            ->toBase()
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row->branch_id] = Money::of(
                BigDecimal::of((int) $row->total_scaled)->dividedBy(100, 2, RoundingMode::Unnecessary)
            );
        }

        return $totals;
    }
}
