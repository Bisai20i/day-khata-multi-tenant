<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Reports\StockValuationReportController;
use App\Models\Account;
use App\Models\AccountHead;
use App\Models\Customer;
use App\Models\Item;
use App\Models\JournalVoucherLine;
use App\Models\Notice;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        // Eager-load the role relation onto the same User instance that
        // HandleInertiaRequests shares as `auth.user`, so the page can
        // read `auth.user.role` without a separate prop.
        $request->user()->loadMissing('role');

        return Inertia::render('Tenant/Dashboard', [
            'notices' => Notice::currentlyActive()->latest()->get(['id', 'title', 'body']),
            'expiringItemsCount' => Item::expiringSoon()->count(),
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
                    'today' => [
                        'count' => Sale::where('status', '!=', 'cancelled')->whereDate('date', now())->count(),
                        'total' => (float) Sale::where('status', '!=', 'cancelled')->whereDate('date', now())->sum('total'),
                    ],
                    'thisWeek' => [
                        'count' => Sale::where('status', '!=', 'cancelled')->where('date', '>=', now()->subWeek())->count(),
                        'total' => (float) Sale::where('status', '!=', 'cancelled')->where('date', '>=', now()->subWeek())->sum('total'),
                    ],
                ],
                'purchases' => [
                    'today' => [
                        'count' => Purchase::where('status', '!=', 'cancelled')->whereDate('date', now())->count(),
                        'total' => (float) Purchase::where('status', '!=', 'cancelled')->whereDate('date', now())->sum('total'),
                    ],
                    'thisWeek' => [
                        'count' => Purchase::where('status', '!=', 'cancelled')->where('date', '>=', now()->subWeek())->count(),
                        'total' => (float) Purchase::where('status', '!=', 'cancelled')->where('date', '>=', now()->subWeek())->sum('total'),
                    ],
                ],
                'cashInHand' => $this->accountBalance('AS1'),
                'stockValue' => (new StockValuationReportController)->currentTotalValuation(),
                'debtors' => $this->subgroupBalance('Sundry Debtors', creditNormal: false),
                'creditors' => $this->subgroupBalance('Sundry Creditors', creditNormal: true),
                'tax' => [
                    'thisWeek' => [
                        'taxable' => (float) Sale::where('status', '!=', 'cancelled')->where('date', '>=', now()->subWeek())->sum('taxable_amount'),
                        'nontaxable' => (float) Sale::where('status', '!=', 'cancelled')->where('date', '>=', now()->subWeek())->sum('nontaxable_amount'),
                        'vat' => (float) Sale::where('status', '!=', 'cancelled')->where('date', '>=', now()->subWeek())->sum('vat_amount'),
                    ],
                ],
            ],
            'salesTrend' => $this->dailyTotals(Sale::class, 7),
            'purchaseTrend' => $this->dailyTotals(Purchase::class, 7),
            'topItemsThisMonth' => SaleLine::query()
                ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
                ->join('items', 'items.id', '=', 'sale_lines.item_id')
                ->where('sales.status', '!=', 'cancelled')
                ->where('sales.date', '>=', now()->startOfMonth())
                ->groupBy('sale_lines.item_id', 'items.name')
                ->orderByDesc('total')
                ->selectRaw('items.name as name')
                ->selectRaw('sum(sale_lines.line_total) as total')
                ->limit(5)
                ->get()
                ->map(fn ($row) => ['name' => $row->name, 'total' => round((float) $row->total, 2)]),
            'topCustomersThisMonth' => Sale::query()
                ->join('customers', 'customers.id', '=', 'sales.customer_id')
                ->where('sales.status', '!=', 'cancelled')
                ->where('sales.date', '>=', now()->startOfMonth())
                ->groupBy('sales.customer_id', 'customers.name')
                ->orderByDesc('total')
                ->selectRaw('customers.name as name')
                ->selectRaw('sum(sales.total) as total')
                ->limit(5)
                ->get()
                ->map(fn ($row) => ['name' => $row->name, 'total' => round((float) $row->total, 2)]),
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
                    'total' => (float) $sale->total,
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
     * Net current balance of a single ledger account (all-time, not
     * fiscal-year-boxed) - the same SUM(debit)-SUM(credit) aggregate
     * AccountingReportController::accountBook() uses for its opening
     * balance, just without a date upper bound.
     */
    private function accountBalance(string $code): float
    {
        $net = JournalVoucherLine::query()
            ->whereHas('account', fn ($query) => $query->where('code', $code))
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');

        return round((float) $net, 2);
    }

    /**
     * Net current balance across every ledger account filed under a given
     * account subgroup (e.g. every customer's own account under "Sundry
     * Debtors") - same aggregate as accountBalance(), summed over the whole
     * subgroup instead of a single account. $creditNormal flips the sign for
     * liability-side subgroups (e.g. "Sundry Creditors") where a credit
     * balance is the normal, positive-looking balance.
     */
    private function subgroupBalance(string $subgroupName, bool $creditNormal): float
    {
        $net = (float) JournalVoucherLine::query()
            ->whereHas('account.subgroup', fn ($query) => $query->where('name', $subgroupName))
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');

        return round($creditNormal ? -$net : $net, 2);
    }

    /**
     * One row per day for the last $days days (oldest first), summing
     * `total` for non-cancelled records dated that day. Reuses the same
     * whereDate() per-day comparison already used above for "today's sales"
     * rather than a raw DATE()/group-by aggregate, so behavior stays
     * identical across database drivers.
     *
     * @param  class-string<Sale>|class-string<Purchase>  $modelClass
     * @return array<int, array{date: string, total: float}>
     */
    private function dailyTotals(string $modelClass, int $days): array
    {
        return collect(range($days - 1, 0))
            ->map(function (int $offset) use ($modelClass) {
                $date = now()->subDays($offset);

                return [
                    'date' => $date->toDateString(),
                    'total' => (float) $modelClass::where('status', '!=', 'cancelled')->whereDate('date', $date)->sum('total'),
                ];
            })
            ->values()
            ->all();
    }
}
