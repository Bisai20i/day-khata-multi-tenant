<?php

namespace App\Support\Branches;

use Illuminate\Http\Request;

/**
 * The on-hand stock a picker or list may quote (plan 12, section 5.7, BR22
 * C1): the item list, the POS tiles and the sale form's item picker.
 *
 * Item::currentStockByItem() with no store is the company-wide total, which
 * told a branch-restricted user how much stock every other branch holds. In a
 * multi-branch company these figures follow the same coverage as a stock
 * report (BranchFilter::fromRequest(), like DashboardController's low-stock
 * card): the switcher's branch, else a restricted user's own branches, else
 * (an unrestricted user on "All branches") the whole company.
 *
 * Display only: Sale::post() still checks stock per store at posting time,
 * and ItemController's deactivate guard still reads the company-wide figure.
 *
 * Parity: a tenant that is not multi-branch gets null stores (the unchanged
 * company-wide query) and a null scope (the pages keep today's labels).
 */
final class BranchStockDisplay
{
    private function __construct(
        private readonly BranchFilter $filter,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(BranchFilter::fromRequest($request));
    }

    /**
     * The stores to sum, in the shape Item::currentStockByItem() takes: null
     * for every store (not multi-branch, or an unrestricted user on all
     * branches), else the covered branches' stores (an empty list when they
     * own none, which quotes zero rather than the company total).
     *
     * @return list<int>|null
     */
    public function storeIds(): ?array
    {
        return $this->filter->storeIds();
    }

    /**
     * What the quoted figure covers, sent to the page as `stockScope` so the
     * label is decided here rather than guessed in the browser: null (not
     * multi-branch, labels unchanged), 'all' (company-wide, for an
     * unrestricted user), 'branch' (one branch) or 'branches' (a restricted
     * user's several branches).
     *
     * @return 'all'|'branch'|'branches'|null
     */
    public function scope(): ?string
    {
        if (! $this->filter->isActive()) {
            return null;
        }

        $ids = $this->filter->effectiveIds();

        if ($ids === null) {
            return 'all';
        }

        return count($ids) > 1 ? 'branches' : 'branch';
    }
}
