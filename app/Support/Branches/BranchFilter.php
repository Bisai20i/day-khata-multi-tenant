<?php

namespace App\Support\Branches;

use App\Models\Branch;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

/**
 * The branch filter of a report (plan 12, section 5.9, BR13): which branches'
 * figures a report shows, and whether it adds "by branch" columns.
 *
 * Request values: `branches[]` (a list of branch ids) or `branch` (one id).
 * Either may also be `all`, an explicit "every branch I may see" that beats
 * the top-bar switcher. Anything else (empty, zero, negative, non-numeric,
 * an id that is not a branch, a branch the user may not use) is ignored
 * rather than an error, so a hand-edited URL never breaks a report. With no
 * usable explicit choice the report follows the switcher
 * (ActiveBranchSelection::narrowedBranchId()), and with the switcher on "All
 * branches" it is not narrowed at all. `by_branch` (boolean) asks for the
 * per-branch columns.
 *
 * Not a security boundary. BranchAccessScope already limits a restricted
 * user's ledger lines to their branches; this only narrows further, never
 * widens: every explicit id is intersected with
 * BranchContext::allowedBranchIds(). Inactive branches stay reportable for an
 * unrestricted user, because a deactivated branch's history still exists.
 *
 * Two sets come out of it, and callers must not mix them up:
 *
 * - lineBranchIds(): the predicate for queries on journal_voucher_lines.
 *   Null when nothing needs adding, which includes a restricted user looking
 *   at all their branches (the access scope already says exactly that).
 * - effectiveIds(): the branches the report covers, for data the access
 *   scope does not reach (closing stock is valued per store). A restricted
 *   user always gets their allowed set here, so their stock is never the
 *   company's while their ledger lines are only their branches'.
 *
 * Parity: a tenant that is not multi-branch (module off, or only Main)
 * resolves to the inactive filter before reading anything, so every report
 * query and payload is exactly what it was, apart from `branchReport`
 * being null.
 */
final class BranchFilter
{
    /**
     * store id => branch id, every store of the company, read once on first
     * use by a stock report (storeBranches()).
     *
     * @var array<int, int>|null
     */
    private ?array $storeBranchMap = null;

    /**
     * @param  list<int>|null  $selectedIds  Explicit or switcher choice, display order; null = not narrowed.
     * @param  list<int>|null  $allowedIds  BranchContext::allowedBranchIds() when the filter was built.
     * @param  list<array{id: int, name: string, code: string, is_main: bool, is_active: bool}>  $branches  Every branch the user may report on, display order.
     */
    private function __construct(
        private readonly bool $multiBranch,
        private readonly ?array $selectedIds,
        private readonly ?array $allowedIds,
        private readonly bool $byBranch,
        private readonly array $branches,
        private readonly string $branchWord,
    ) {}

    /**
     * The filter of a tenant that is not multi-branch: narrows nothing.
     */
    public static function inactive(): self
    {
        return new self(false, null, null, false, [], 'Branch');
    }

    /**
     * Reads the request. One query (the branches table, tiny) on top of the
     * switcher's own cached read, and only for a multi-branch tenant.
     */
    public static function fromRequest(Request $request): self
    {
        if (! ActiveBranchSelection::isMultiBranch($request)) {
            return self::inactive();
        }

        $allowed = BranchContext::allowedBranchIds();
        $branches = self::reportableBranches($allowed);
        $knownIds = array_map(fn (array $branch): int => $branch['id'], $branches);

        $requested = self::requestedChoice($request);

        if ($requested === false) {
            // No usable explicit choice: follow the switcher.
            $switcherId = ActiveBranchSelection::narrowedBranchId($request);
            $selected = $switcherId !== null && in_array($switcherId, $knownIds, true) ? [$switcherId] : null;
        } elseif ($requested === null) {
            $selected = null;
        } else {
            $selected = array_values(array_filter($knownIds, fn (int $id): bool => in_array($id, $requested, true)));

            if ($selected === []) {
                $switcherId = ActiveBranchSelection::narrowedBranchId($request);
                $selected = $switcherId !== null && in_array($switcherId, $knownIds, true) ? [$switcherId] : null;
            } elseif ($allowed === null && count($selected) === count($knownIds)) {
                // Every branch there is, for a user who may see them all: the
                // same as no narrowing, so the report takes the unchanged
                // company-wide path.
                $selected = null;
            }
        }

        return new self(
            true,
            $selected,
            $allowed,
            $request->boolean('by_branch'),
            $branches,
            ActiveBranchSelection::label(),
        );
    }

    /**
     * Whether the request asks for the per-branch columns. Always false for
     * a tenant that is not multi-branch, whatever the query string says.
     */
    public static function byBranchRequested(Request $request): bool
    {
        return ActiveBranchSelection::isMultiBranch($request) && $request->boolean('by_branch');
    }

    /**
     * Narrows a ledger-line query to $branchIds, directly on the lines table
     * (plan 12, section 8: not through whereHas). Null adds nothing at all.
     *
     * @template TBuilder of Builder|QueryBuilder
     *
     * @param  TBuilder  $lines
     * @param  list<int>|null  $branchIds
     * @return TBuilder
     */
    public static function narrowLines(Builder|QueryBuilder $lines, ?array $branchIds, string $column = 'journal_voucher_lines.branch_id'): Builder|QueryBuilder
    {
        if ($branchIds !== null) {
            $lines->whereIn($column, $branchIds);
        }

        return $lines;
    }

    /**
     * Whether the tenant is multi-branch, i.e. the report shows a branch
     * filter at all.
     */
    public function isActive(): bool
    {
        return $this->multiBranch;
    }

    /**
     * Whether the report covers less than the whole company: an explicit or
     * switcher choice, or a restricted user.
     */
    public function isNarrowed(): bool
    {
        return $this->effectiveIds() !== null;
    }

    /**
     * The explicit or switcher choice, display order; null when the report
     * is not narrowed by a choice (a restricted user still only sees their
     * branches, see effectiveIds()).
     *
     * @return list<int>|null
     */
    public function ids(): ?array
    {
        return $this->selectedIds;
    }

    /**
     * The branches the report covers: the choice, else a restricted user's
     * allowed set, else null (the whole company).
     *
     * @return list<int>|null
     */
    public function effectiveIds(): ?array
    {
        if (! $this->multiBranch) {
            return null;
        }

        return $this->selectedIds ?? $this->allowedIds;
    }

    /**
     * The predicate for journal_voucher_lines queries, or null to add none.
     * A restricted user whose choice is exactly their allowed set gets null:
     * BranchAccessScope already applies that very IN list.
     *
     * @return list<int>|null
     */
    public function lineBranchIds(): ?array
    {
        if (! $this->multiBranch || $this->selectedIds === null) {
            return null;
        }

        if ($this->allowedIds !== null && self::sameSet($this->selectedIds, $this->allowedIds)) {
            return null;
        }

        return $this->selectedIds;
    }

    public function isSingle(): bool
    {
        $ids = $this->effectiveIds();

        return $ids !== null && count($ids) === 1;
    }

    public function singleId(): ?int
    {
        return $this->isSingle() ? $this->effectiveIds()[0] : null;
    }

    /**
     * Whether the per-branch columns are on (multi-branch tenants only).
     */
    public function byBranch(): bool
    {
        return $this->multiBranch && $this->byBranch;
    }

    /**
     * Narrows a ledger-line query by lineBranchIds().
     *
     * @template TBuilder of Builder|QueryBuilder
     *
     * @param  TBuilder  $lines
     * @return TBuilder
     */
    public function applyToLines(Builder|QueryBuilder $lines, string $column = 'journal_voucher_lines.branch_id'): Builder|QueryBuilder
    {
        return self::narrowLines($lines, $this->lineBranchIds(), $column);
    }

    /**
     * Narrows a document query (sales, purchases, returns, capital
     * documents, or a line table joined to one) to the branches the report
     * covers, on the DOCUMENT's own branch_id (BR14). A return carries its
     * original's branch, so it is filtered on its own column too.
     *
     * Uses effectiveIds(), not lineBranchIds(): line tables joined to a
     * document (sale_lines, purchase_lines) carry no access scope, so a
     * restricted user's allowed set must be applied explicitly there. On a
     * scoped document model the extra IN only repeats BranchAccessScope.
     * Null (not multi-branch, or an unrestricted user on all branches) adds
     * nothing, so the query is exactly what it was.
     *
     * @template TBuilder of Builder|QueryBuilder
     *
     * @param  TBuilder  $query
     * @param  string  $column  The document's branch column, table-qualified when the query joins.
     * @return TBuilder
     */
    public function applyToDocuments(Builder|QueryBuilder $query, string $column = 'branch_id'): Builder|QueryBuilder
    {
        return self::narrowLines($query, $this->effectiveIds(), $column);
    }

    /**
     * Every store of the company and its branch (store id => branch id), for
     * the stock reports (BR15): stock belongs to a store and a store to
     * exactly one branch (I5), so a branch's stock is its stores' stock.
     * Whole-truth read, like BranchReportTotals::storeBranches(): the map is
     * a company fact used to value the covered set, never shown as such.
     * One query, on first use only, so a report that never narrows (and a
     * tenant that is not multi-branch) never runs it.
     *
     * @return array<int, int>
     */
    public function storeBranches(): array
    {
        return $this->storeBranchMap ??= BranchContext::unrestricted(fn (): array => Store::query()
            ->orderBy('id')
            ->get(['id', 'branch_id'])
            ->mapWithKeys(fn (Store $store): array => [(int) $store->id => (int) $store->branch_id])
            ->all());
    }

    /**
     * The stores of the branches the report covers, or null for every store
     * (not multi-branch, or an unrestricted user on all branches: the stock
     * queries stay exactly what they were). An empty list means the covered
     * branches own no store, so the report shows no stock.
     *
     * @return list<int>|null
     */
    public function storeIds(): ?array
    {
        $ids = $this->effectiveIds();

        if ($ids === null) {
            return null;
        }

        return array_keys(array_filter($this->storeBranches(), fn (int $branchId): bool => in_array($branchId, $ids, true)));
    }

    /**
     * An explicitly requested store, kept only when it lies inside the
     * covered branches; a store outside them is dropped (null), so the
     * report falls back to the branches' own stores and the page resets its
     * store dropdown. Not narrowed: returned unchanged (parity).
     */
    public function allowedStoreId(?int $storeId): ?int
    {
        if ($storeId === null) {
            return null;
        }

        $storeIds = $this->storeIds();

        return $storeIds === null || in_array($storeId, $storeIds, true) ? $storeId : null;
    }

    /**
     * What a stock query is narrowed by: the requested store (already passed
     * through allowedStoreId()), else the covered branches' stores, else null
     * for every store. The shape Item::currentStockByItem() takes.
     *
     * @return int|list<int>|null
     */
    public function stockScope(?int $storeId): int|array|null
    {
        return $storeId ?? $this->storeIds();
    }

    /**
     * The `branch` key a report row gets in a multi-branch tenant (the
     * document's branch name, from the list already loaded, no query), or
     * nothing at all otherwise, so a single-branch payload keeps its exact
     * shape.
     *
     * @return array{branch?: string|null}
     */
    public function rowBranch(int|string|null $branchId): array
    {
        if (! $this->multiBranch) {
            return [];
        }

        return ['branch' => $this->branchName($branchId === null ? null : (int) $branchId)];
    }

    /**
     * Human label for headers, in the company's own word for a branch:
     * "All branches", "Branch: Kathmandu", "Branches: Kathmandu, Pokhara"
     * ("Site: ..." for a company that calls them sites).
     */
    public function label(): string
    {
        $ids = $this->effectiveIds();
        $plural = $this->branchWord === 'Branch' ? 'Branches' : $this->branchWord.'s';

        if ($ids === null) {
            return 'All '.strtolower($plural);
        }

        $names = array_values(array_map(
            fn (array $branch): string => $branch['name'],
            array_filter($this->branches, fn (array $branch): bool => in_array($branch['id'], $ids, true)),
        ));

        if ($names === []) {
            return 'No '.strtolower($plural);
        }

        $label = (count($names) === 1 ? $this->branchWord : $plural).': '.implode(', ', $names);

        // A restricted user who chose nothing sees their whole allowed set,
        // never "All branches": company-wide figures are not theirs to see.
        return $this->selectedIds === null && $this->allowedIds !== null ? $label.' (your access)' : $label;
    }

    /**
     * Name of a branch the user may report on, for row labels (the day
     * book's branch column). Read from the list this filter already loaded,
     * so it never costs a query.
     */
    public function branchName(?int $branchId): ?string
    {
        foreach ($this->branches as $branch) {
            if ($branch['id'] === $branchId) {
                return $branch['name'];
            }
        }

        return null;
    }

    /**
     * Every branch the user may report on (inactive ones included for an
     * unrestricted user), for the filter control.
     *
     * @return list<array{id: int, name: string, code: string, is_main: bool, is_active: bool}>
     */
    public function options(): array
    {
        return $this->branches;
    }

    /**
     * The per-branch columns, display order: the covered branches when the
     * report is narrowed; otherwise every active branch plus any inactive
     * one in $branchIdsWithData (so the columns always add up to the
     * company figure).
     *
     * @param  list<int>  $branchIdsWithData
     * @return list<array{id: int, name: string, code: string}>
     */
    public function columns(array $branchIdsWithData = []): array
    {
        $ids = $this->effectiveIds();

        $branches = array_filter(
            $this->branches,
            fn (array $branch): bool => $ids !== null
                ? in_array($branch['id'], $ids, true)
                : $branch['is_active'] || in_array($branch['id'], $branchIdsWithData, true),
        );

        return array_values(array_map(
            fn (array $branch): array => ['id' => $branch['id'], 'name' => $branch['name'], 'code' => $branch['code']],
            $branches,
        ));
    }

    /**
     * The `branchReport` Inertia prop, or null for a tenant that is not
     * multi-branch.
     *
     * `selected` is the explicit or switcher choice (null: not narrowed by a
     * choice); `narrowed` is true whenever the figures cover less than the
     * whole company, including a restricted user's own branches;
     * `restricted` is true for a user limited to some branches (company-wide
     * totals are never available to them); `byBranch` and `management` are
     * only ever true on the reports that support them.
     *
     * @return array{selected: list<int>|null, narrowed: bool, restricted: bool, label: string, options: list<array{id: int, name: string, code: string, is_main: bool, is_active: bool}>, byBranch: bool, management: bool}|null
     */
    public function payload(bool $supportsByBranch = false, bool $isStatement = false): ?array
    {
        if (! $this->multiBranch) {
            return null;
        }

        $byBranch = $supportsByBranch && $this->byBranch;

        return [
            'selected' => $this->selectedIds,
            'narrowed' => $this->isNarrowed(),
            'restricted' => $this->allowedIds !== null,
            'label' => $this->label(),
            'options' => $this->branches,
            'byBranch' => $byBranch,
            // A statement for part of the company is a management report
            // (plan 12, section 5.9): shared costs sit where they were posted.
            'management' => $isStatement && ($this->isNarrowed() || $byBranch),
        ];
    }

    /**
     * What the request explicitly asked for: false for nothing usable, null
     * for "all", else the requested ids (not yet checked against anything).
     *
     * @return list<int>|null|false
     */
    private static function requestedChoice(Request $request): array|null|false
    {
        $raw = $request->input('branches');

        if (! is_array($raw)) {
            // A scalar `branches=3` reads like `branch=3`.
            $single = is_scalar($raw) && trim((string) $raw) !== '' ? $raw : $request->input('branch');
            $raw = is_scalar($single) ? [$single] : [];
        }

        $ids = [];

        foreach ($raw as $value) {
            $normalized = is_scalar($value) ? ActiveBranchSelection::normalize(is_string($value) ? trim($value) : $value) : null;

            if ($normalized === ActiveBranchSelection::ALL) {
                return null;
            }

            if (is_int($normalized)) {
                $ids[$normalized] = $normalized;
            }
        }

        return $ids === [] ? false : array_values($ids);
    }

    /**
     * Every branch the user may report on, display order: all of them
     * (active or not) for an unrestricted user, else their allowed set.
     *
     * @param  list<int>|null  $allowed
     * @return list<array{id: int, name: string, code: string, is_main: bool, is_active: bool}>
     */
    private static function reportableBranches(?array $allowed): array
    {
        return Branch::query()
            ->when($allowed !== null, fn (Builder $query) => $query->whereKey($allowed))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'is_main', 'is_active'])
            ->map(fn (Branch $branch): array => [
                'id' => (int) $branch->id,
                'name' => (string) $branch->name,
                'code' => (string) $branch->code,
                'is_main' => (bool) $branch->is_main,
                'is_active' => (bool) $branch->is_active,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $left
     * @param  list<int>  $right
     */
    private static function sameSet(array $left, array $right): bool
    {
        sort($left);
        sort($right);

        return $left === $right;
    }
}
