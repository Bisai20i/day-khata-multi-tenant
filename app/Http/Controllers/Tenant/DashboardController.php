<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountHead;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalVoucherLine;
use App\Models\Notice;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Supplier;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every money figure on this page is an exact 2-decimal string produced by a
 * scaled-integer SQL SUM, and every ledger balance is boxed inside ONE
 * fiscal year.
 *
 * Both of those are fixes, not style. The KPIs used to be `(float) sum()`,
 * which on SQLite reads a decimal column back as a float; and cash in hand,
 * debtors and creditors were all-time cumulative, so the moment a tenant
 * closed its first year the new year's Opening Balance voucher restated the
 * same balances the old year's lines had already produced and every one of
 * those three doubled (audit P0-18).
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        // Eager-load the role relation onto the same User instance that
        // HandleInertiaRequests shares as `auth.user`, so the page can
        // read `auth.user.role` without a separate prop.
        $request->user()->loadMissing('role');

        $fiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();
        $today = now()->toDateString();

        return Inertia::render('Tenant/Dashboard', [
            'notices' => Notice::currentlyActive()->latest()->get(['id', 'title', 'body']),
            'expiringItemsCount' => Item::expiringSoon()->count(),
            'fiscalYear' => $fiscalYear === null ? null : [
                'id' => $fiscalYear->id,
                'name' => $fiscalYear->name,
                'startDate' => $fiscalYear->start_date->toDateString(),
                'endDate' => $fiscalYear->end_date->toDateString(),
            ],
            'kpis' => [
                'customers' => [
                    'total' => Customer::count(),
                    'thisWeek' => Customer::where('created_at', '>=', now()->subWeek())->count(),
                ],
                'suppliers' => [
                    'total' => Supplier::count(),
                    'thisWeek' => Supplier::where('created_at', '>=', now()->subWeek())->count(),
                ],
                'items' => [
                    'total' => Item::count(),
                    'thisWeek' => Item::where('created_at', '>=', now()->subWeek())->count(),
                ],
                'accounts' => [
                    'total' => Account::count(),
                ],
                'sales' => [
                    'today' => $this->documentTotals(Sale::class, whereDate: $today),
                    'thisWeek' => $this->documentTotals(Sale::class, since: now()->subWeek()->toDateString()),
                ],
                'purchases' => [
                    'today' => $this->documentTotals(Purchase::class, whereDate: $today),
                    'thisWeek' => $this->documentTotals(Purchase::class, since: now()->subWeek()->toDateString()),
                ],
                'cashInHand' => $this->accountBalance('AS1', $fiscalYear)->toString(),
                // Read through StockCosting, the single place stock is valued
                // (CONTRACTS C10), so the dashboard figure is the same one the
                // Balance Sheet and the year-end closing entry use.
                'stockValue' => StockCosting::totalClosingValue($today)->toString(),
                'debtors' => $this->subgroupBalance('Sundry Debtors', $fiscalYear, creditNormal: false)->toString(),
                'creditors' => $this->subgroupBalance('Sundry Creditors', $fiscalYear, creditNormal: true)->toString(),
                'tax' => [
                    'thisWeek' => [
                        'taxable' => $this->sumColumn($this->activeDocuments(Sale::class, since: now()->subWeek()->toDateString()), 'taxable_amount')->toString(),
                        'nontaxable' => $this->sumColumn($this->activeDocuments(Sale::class, since: now()->subWeek()->toDateString()), 'nontaxable_amount')->toString(),
                        'vat' => $this->sumColumn($this->activeDocuments(Sale::class, since: now()->subWeek()->toDateString()), 'vat_amount')->toString(),
                    ],
                ],
            ],
            'salesTrend' => $this->dailyTotals(Sale::class, 7),
            'purchaseTrend' => $this->dailyTotals(Purchase::class, 7),
            'lowStockItems' => $this->lowStockItems(),
            'topItemsThisMonth' => SaleLine::query()
                ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
                ->join('items', 'items.id', '=', 'sale_lines.item_id')
                ->where('sales.status', '!=', 'cancelled')
                ->where('sales.date', '>=', now()->startOfMonth())
                ->groupBy('sale_lines.item_id', 'items.name')
                ->orderByDesc('total_scaled')
                ->selectRaw('items.name as name')
                ->selectRaw('sum('.$this->scaledMoney('sale_lines.line_total').') as total_scaled')
                ->limit(5)
                ->get()
                ->map(fn ($row) => ['name' => $row->name, 'total' => $this->fromScaled($row->total_scaled)->toString()]),
            'topCustomersThisMonth' => Sale::query()
                ->join('customers', 'customers.id', '=', 'sales.customer_id')
                ->where('sales.status', '!=', 'cancelled')
                ->where('sales.date', '>=', now()->startOfMonth())
                ->groupBy('sales.customer_id', 'customers.name')
                ->orderByDesc('total_scaled')
                ->selectRaw('customers.name as name')
                ->selectRaw('sum('.$this->scaledMoney('sales.total').') as total_scaled')
                ->limit(5)
                ->get()
                ->map(fn ($row) => ['name' => $row->name, 'total' => $this->fromScaled($row->total_scaled)->toString()]),
            'recentSales' => Sale::with('customer:id,name')
                ->where('status', '!=', 'cancelled')
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->take(6)
                ->get()
                ->map(fn (Sale $sale) => [
                    'id' => $sale->id,
                    'customer' => $sale->customer?->name,
                    'date' => $sale->date->format('M j, Y'),
                    'total' => Money::of($sale->total)->toString(),
                    'paymentMode' => $sale->payment_mode,
                ]),
            'recentCustomers' => Customer::with('account')->latest()->take(5)->get()->map(fn (Customer $customer) => [
                'name' => $customer->name,
                'mobile' => $customer->mobile_no,
                'code' => $customer->account?->code,
                'added' => $customer->created_at->diffForHumans(),
            ]),
            'accountHeadBreakdown' => AccountHead::all()->map(function (AccountHead $head) {
                $count = Account::where(function ($query) use ($head) {
                    $query->whereHas('group', fn ($g) => $g->where('account_head_id', $head->id))
                        ->orWhereHas('subgroup.accountGroup', fn ($g) => $g->where('account_head_id', $head->id));
                })->count();

                return ['name' => $head->name, 'count' => $count];
            }),
        ]);
    }

    /**
     * Items at or below their reorder level, read through the same batch
     * stock query every list screen uses (CONTRACTS C10) rather than a
     * PHP-side sum over movements. Items with no min_stock set are not "low",
     * they simply have no reorder level.
     *
     * @return array<int, array{id: int, name: string, unit: string, stock: string, minStock: string}>
     */
    private function lowStockItems(): array
    {
        $items = Item::query()
            ->where('is_stockable', true)
            ->whereNotNull('min_stock')
            ->where('min_stock', '>', 0)
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'min_stock']);

        if ($items->isEmpty()) {
            return [];
        }

        $stock = Item::currentStockByItem($items->modelKeys());

        return $items
            ->map(function (Item $item) use ($stock) {
                $onHand = $stock[$item->getKey()] ?? Quantity::zero();
                $minimum = Quantity::of($item->min_stock);

                if ($onHand->isGreaterThan($minimum)) {
                    return null;
                }

                return [
                    'id' => $item->getKey(),
                    'name' => $item->name,
                    'unit' => $item->unit,
                    'stock' => $onHand->toString(),
                    'minStock' => $minimum->toString(),
                ];
            })
            ->filter()
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @param  class-string<Sale>|class-string<Purchase>  $modelClass
     * @return array{count: int, total: string}
     */
    private function documentTotals(string $modelClass, ?string $whereDate = null, ?string $since = null): array
    {
        $query = $this->activeDocuments($modelClass, $whereDate, $since);

        return [
            'count' => (clone $query)->count(),
            'total' => $this->sumColumn($query, 'total')->toString(),
        ];
    }

    /**
     * @param  class-string<Sale>|class-string<Purchase>  $modelClass
     * @return Builder<Sale|Purchase>
     */
    private function activeDocuments(string $modelClass, ?string $whereDate = null, ?string $since = null)
    {
        return $modelClass::query()
            ->where('status', '!=', 'cancelled')
            ->when($whereDate !== null, fn ($query) => $query->whereDate('date', $whereDate))
            ->when($since !== null, fn ($query) => $query->whereDate('date', '>=', $since));
    }

    /**
     * Net balance of a single ledger account within one fiscal year - never
     * all-time. See the class docblock for the double-counting this fixes.
     */
    private function accountBalance(string $code, ?FiscalYear $fiscalYear): Money
    {
        if ($fiscalYear === null) {
            return Money::zero();
        }

        return $this->ledgerNet(
            JournalVoucherLine::query()->whereHas('account', fn ($query) => $query->where('code', $code)),
            $fiscalYear,
        );
    }

    /**
     * Net balance across every ledger account filed under a given account
     * subgroup (for example every customer's own account under "Sundry
     * Debtors"), within one fiscal year. $creditNormal flips the sign for
     * liability-side subgroups, where a credit balance is the normal,
     * positive-looking one.
     */
    private function subgroupBalance(string $subgroupName, ?FiscalYear $fiscalYear, bool $creditNormal): Money
    {
        if ($fiscalYear === null) {
            return Money::zero();
        }

        $net = $this->ledgerNet(
            JournalVoucherLine::query()->whereHas('account.subgroup', fn ($query) => $query->where('name', $subgroupName)),
            $fiscalYear,
        );

        return $creditNormal ? $net->negated() : $net;
    }

    /**
     * @param  Builder<JournalVoucherLine>  $query
     */
    private function ledgerNet($query, FiscalYear $fiscalYear): Money
    {
        $netScaled = $query
            ->whereHas('journalVoucher', fn ($voucher) => $voucher->where('fiscal_year_id', $fiscalYear->id))
            ->selectRaw(
                'COALESCE(SUM('.$this->scaledMoney('debit').'), 0) - COALESCE(SUM('.$this->scaledMoney('credit').'), 0) as net_scaled'
            )
            ->value('net_scaled');

        return $this->fromScaled($netScaled);
    }

    /**
     * One row per day for the last $days days (oldest first), summing
     * `total` for non-cancelled records dated that day.
     *
     * @param  class-string<Sale>|class-string<Purchase>  $modelClass
     * @return array<int, array{date: string, total: string}>
     */
    private function dailyTotals(string $modelClass, int $days): array
    {
        return collect(range($days - 1, 0))
            ->map(function (int $offset) use ($modelClass) {
                $date = now()->subDays($offset)->toDateString();

                return [
                    'date' => $date,
                    'total' => $this->sumColumn($this->activeDocuments($modelClass, whereDate: $date), 'total')->toString(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  BuilderContract|\Illuminate\Database\Eloquent\Builder<*>  $query
     */
    private function sumColumn($query, string $column): Money
    {
        return $this->fromScaled(
            (clone $query)->selectRaw('COALESCE(SUM('.$this->scaledMoney($column).'), 0) as total_scaled')->value('total_scaled')
        );
    }

    /**
     * A money column as a scaled integer. SQLite gives a decimal column REAL
     * affinity, so a plain SUM() there is a float sum with the rounding error
     * this whole phase exists to remove; multiplying by 100 and casting makes
     * the sum exact on SQLite and MySQL alike.
     */
    private function scaledMoney(string $column): string
    {
        $cast = JournalVoucherLine::query()->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        return "CAST(ROUND({$column} * 100) AS {$cast})";
    }

    private function fromScaled(int|float|string|null $scaled): Money
    {
        return Money::of(
            BigDecimal::of((int) ($scaled ?? 0))->dividedBy(100, 2, RoundingMode::Unnecessary)
        );
    }
}
