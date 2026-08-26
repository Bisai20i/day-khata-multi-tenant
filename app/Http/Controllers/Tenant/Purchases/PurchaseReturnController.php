<?php

namespace App\Http\Controllers\Tenant\Purchases;

use App\Http\Controllers\Controller;
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
                ->with(['purchase.supplier:id,name', 'lines.purchaseLine.item:id,name,unit'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'purchases' => Purchase::query()
                ->where('status', 'posted')
                ->with(['supplier:id,name', 'lines.item:id,name,unit'])
                ->orderByDesc('date')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'purchase_id' => ['required', 'exists:purchases,id'],
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
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
}
