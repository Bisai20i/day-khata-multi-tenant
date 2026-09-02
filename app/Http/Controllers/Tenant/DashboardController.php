<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountHead;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Notice;
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
            ],
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
}
