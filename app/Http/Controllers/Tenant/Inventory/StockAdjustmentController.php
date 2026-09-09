<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\StockAdjustment;
use App\Models\Store;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class StockAdjustmentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Inventory/StockAdjustments/Index', [
            'stockAdjustments' => StockAdjustment::query()
                ->with(['lines.item:id,name,unit'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'items' => Item::query()->where('is_stockable', true)->orderBy('name')->get(['id', 'name', 'unit']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(),
            // See PurchaseController::index()'s identical prop for the
            // rationale - the one closed year currently reopened for
            // correction, if any.
            'correctionFiscalYear' => FiscalYear::openForCorrection()?->only(['id', 'name', 'reopen_reason']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'fiscal_year_id' => ['nullable', 'integer', 'exists:fiscal_years,id'],
            'reason' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.direction' => ['required', 'in:in,out'],
            'lines.*.reason_type' => ['required', 'in:damage,lost,correction,found,opening,other'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_cost_rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            StockAdjustment::post(
                Arr::except($data, ['lines']),
                $data['lines'],
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        } catch (AuthorizationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.stock-adjustments.index')->with('status', 'Stock adjustment posted.');
    }

    public function cancel(Request $request, StockAdjustment $stock_adjustment): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $stock_adjustment->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.stock-adjustments.index')->with('status', 'Stock adjustment cancelled.');
    }
}
