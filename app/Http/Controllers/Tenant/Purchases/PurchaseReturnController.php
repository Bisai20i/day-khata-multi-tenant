<?php

namespace App\Http\Controllers\Tenant\Purchases;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PurchaseReturnController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Purchases/Returns/Index', [
            'returns' => PurchaseReturn::query()
                ->with(['purchase.supplier:id,name', 'lines.purchaseLine.item:id,name,unit', 'refundAccount:id,code,name'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'purchases' => Purchase::query()
                ->where('status', 'posted')
                ->with(['supplier:id,name', 'lines.item:id,name,unit'])
                ->orderByDesc('date')
                ->get(),
            'accounts' => Account::query()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'purchase_id' => ['required', 'exists:purchases,id'],
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'refund_account_id' => ['nullable', 'exists:accounts,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_line_id' => ['required', 'exists:purchase_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        ]);

        try {
            PurchaseReturn::post($data, $data['lines'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.purchase-returns.index')->with('status', 'Purchase return posted.');
    }

    public function cancel(Request $request, PurchaseReturn $purchaseReturn): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $purchaseReturn->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.purchase-returns.index')->with('status', 'Purchase return cancelled.');
    }
}
