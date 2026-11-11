<?php

namespace App\Http\Controllers\Tenant\Sales;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Concerns\OffersDocumentBranch;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchFormOptions;
use App\Support\Branches\BranchStockDisplay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * POS / walk-in quick-sale screen. Frontend-only: the actual sale is
 * submitted through the existing `POST /sales` route
 * (App\Http\Controllers\Tenant\Sales\SaleController::store()), so this
 * controller only needs to hand the page the same reference lists
 * SaleController::index() already provides, plus item categories for the
 * page's category filter row and each stockable item's current on-hand
 * quantity so the item tiles can show a stock badge and warn the cashier
 * when a cart's quantity would exceed it (mirroring the legacy POS's
 * "Total in all carts exceeds available stock" warning).
 *
 * Branches (plan 12, BR12): a terminal sells into one branch at a time, the
 * top-bar switcher's branch (else the user's default branch). A multi-branch
 * user who may use several branches can change it from the checkout panel;
 * every cart's store must then be one of that branch's stores, so the page
 * clears a held cart's store that falls outside it (the server would refuse
 * it) and the sale takes the branch's default store. The item tiles' stock
 * badge covers the switcher's branch (else a restricted user's own branches,
 * else the whole company for an unrestricted user on "All branches"); see
 * BranchStockDisplay (BR22 C1).
 */
class PosController extends Controller
{
    use OffersDocumentBranch;

    /**
     * Party ledgers are never a settlement account - see
     * SaleController::settlementAccounts() for the full reasoning. Duplicated
     * here rather than shared, because the two controllers are owned by
     * different route files and neither should reach into the other.
     */
    private const PARTY_SUBGROUPS = ['Sundry Debtors', 'Sundry Creditors', 'Sales Agents'];

    public function index(Request $request): Response
    {
        $settings = CompanySetting::current();

        $items = Item::query()->where('is_active', true)->orderBy('name')
            ->get(['id', 'name', 'unit', 'is_vatable', 'is_stockable', 'item_category_id', 'sale_rate', 'image_path', 'barcode']);

        $stockDisplay = BranchStockDisplay::fromRequest($request);
        $stock = Item::currentStockByItem($items->pluck('id')->all(), $stockDisplay->storeIds());

        $items = $items->map(function (Item $item) use ($stock) {
            // An exact 4dp string, not a float: the cashier's screen compares
            // this against cart quantities through the shared money module,
            // which never accepts a float (CONTRACTS C8).
            $item->current_stock = $item->is_stockable
                ? ($stock[$item->id] ?? null)?->toString()
                : null;

            return $item;
        });

        return Inertia::render('Tenant/Sales/Pos', [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'mobile_no']),
            'items' => $items,
            // What the tile badges cover; null when not multi-branch.
            'stockScope' => $stockDisplay->scope(),
            'categories' => ItemCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'bankAccounts' => $this->settlementAccounts(),
            'tdsAccounts' => $this->settlementAccounts(),
            'stores' => $this->branchStoreOptions($request),
            // POS defaults every new cart to the walk-in customer (audit
            // section 3 "Sales") - a quick counter sale usually has nobody
            // to name.
            'walkInCustomerId' => Customer::walkIn()?->id,
            // Legacy's top bar always shows "FY: <label>" (new-pos.blade.php)
            // - mirrored here as a plain display badge, never posted anywhere.
            // Deliberately not FiscalYear::current(), which firstOrFail()s
            // (appropriate for a posting path, not for this purely cosmetic
            // badge) - a tenant with no open fiscal year would 404 the whole
            // POS screen instead of just showing no badge.
            // Company Information name, shown centred in the POS header.
            'companyName' => $settings->company_name,
            'currentFiscalYear' => FiscalYear::where('status', FiscalYearStatus::Open)->first()?->name,
            'invoiceSettings' => [
                'default_vat_rate' => $settings->default_vat_rate,
                'default_store_id' => $settings->default_store_id,
                'active_invoice_type' => $settings->active_invoice_type ?? 'full',
            ],
            ...BranchFormOptions::props($request),
        ]);
    }

    /**
     * Cash, bank and TDS ledgers: every balance-sheet account that is not a
     * party ledger, nor an inter-branch control account (BranchAccounts;
     * SaleController::settlementAccountQuery() refuses those on submit).
     *
     * @return Collection<int, Account>
     */
    private function settlementAccounts(): Collection
    {
        return BranchAccounts::exclude(Account::query())
            ->where(function (Builder $query) {
                $query->whereHas('group.accountHead', fn (Builder $head) => $head->where('is_profit_and_loss', false))
                    ->orWhereHas('subgroup.accountGroup.accountHead', fn (Builder $head) => $head->where('is_profit_and_loss', false));
            })
            ->whereDoesntHave('subgroup', fn (Builder $subgroup) => $subgroup->whereIn('name', self::PARTY_SUBGROUPS))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }
}
