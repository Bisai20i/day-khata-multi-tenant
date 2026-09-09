<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\StockTransfer;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class StockTransferController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Inventory/StockTransfers/Index', [
            'stockTransfers' => StockTransfer::query()
                ->with(['lines.item:id,name,unit', 'fromStore:id,name', 'toStore:id,name'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'items' => Item::query()->where('is_stockable', true)->orderBy('name')->get(['id', 'name', 'unit']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'from_store_id' => ['required', 'integer', 'exists:stores,id'],
            'to_store_id' => ['required', 'integer', 'exists:stores,id', 'different:from_store_id'],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_cost_rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.remarks' => ['nullable', 'string', 'max:255'],
        ], [
            'to_store_id.different' => 'The source and destination store must be different.',
        ]);

        try {
            StockTransfer::post(
                Arr::except($data, ['lines']),
                $data['lines'],
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.stock-transfers.index')->with('status', 'Stock transfer posted.');
    }

    public function cancel(Request $request, StockTransfer $stock_transfer): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $stock_transfer->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.stock-transfers.index')->with('status', 'Stock transfer cancelled.');
    }
}
