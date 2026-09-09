<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\StockConversion;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class StockConversionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Inventory/StockConversions/Index', [
            'stockConversions' => StockConversion::query()
                ->with(['lines.item:id,name,unit', 'store:id,name'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'items' => Item::query()->where('is_stockable', true)->orderBy('name')->get(['id', 'name', 'unit']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:production,refining'],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'input_lines' => ['required', 'array', 'min:1'],
            'input_lines.*.item_id' => ['required', 'exists:items,id'],
            'input_lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'input_lines.*.unit_cost_rate' => ['nullable', 'numeric', 'min:0'],
            'input_lines.*.remarks' => ['nullable', 'string', 'max:255'],
            'output_lines' => ['required', 'array', 'min:1'],
            'output_lines.*.item_id' => ['required', 'exists:items,id'],
            'output_lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'output_lines.*.unit_cost_rate' => ['nullable', 'numeric', 'min:0'],
            'output_lines.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            StockConversion::post(
                Arr::only($data, ['type', 'date', 'note', 'store_id']),
                $data['input_lines'],
                $data['output_lines'],
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['input_lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.stock-conversions.index')->with('status', 'Stock conversion posted.');
    }

    public function cancel(Request $request, StockConversion $stock_conversion): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $stock_conversion->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.stock-conversions.index')->with('status', 'Stock conversion cancelled.');
    }
}
