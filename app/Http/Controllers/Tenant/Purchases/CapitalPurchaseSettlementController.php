<?php

namespace App\Http\Controllers\Tenant\Purchases;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Purchases\CancelCapitalPurchaseSettlementRequest;
use App\Http\Requests\Tenant\Purchases\SettleCapitalPurchaseRequest;
use App\Models\CapitalPurchase;
use App\Models\CapitalPurchaseSettlement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

class CapitalPurchaseSettlementController extends Controller
{
    public function store(SettleCapitalPurchaseRequest $request, CapitalPurchase $capitalPurchase): RedirectResponse
    {
        try {
            CapitalPurchaseSettlement::settle($capitalPurchase, $request->validated(), $request->user());
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.capital-purchases.index')->with('status', 'Settlement posted.');
    }

    public function cancel(CancelCapitalPurchaseSettlementRequest $request, CapitalPurchaseSettlement $settlement): RedirectResponse
    {
        try {
            $settlement->cancel($request->user(), $request->validated('reason'));
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.capital-purchases.index')->with('status', 'Settlement cancelled.');
    }
}
