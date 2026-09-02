<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemVariety;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ItemVarietyController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Inventory/ItemVarieties/Index', [
            'varieties' => ItemVariety::query()->with('item:id,name')->orderBy('name')->get(),
            'items' => Item::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ItemVariety::create($this->validated($request));

        return redirect()->route('tenant.item-varieties.index')->with('status', 'Variety added.');
    }

    public function update(Request $request, ItemVariety $itemVariety): RedirectResponse
    {
        $itemVariety->update($this->validated($request));

        return redirect()->route('tenant.item-varieties.index')->with('status', 'Variety updated.');
    }

    public function destroy(ItemVariety $itemVariety): RedirectResponse
    {
        $itemVariety->delete();

        return redirect()->route('tenant.item-varieties.index')->with('status', 'Variety deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'item_id' => ['required', 'exists:items,id'],
            'name' => ['required', 'string', 'max:255'],
            'sku_suffix' => ['nullable', 'string', 'max:100'],
            'price_adjustment' => ['numeric'],
            'is_active' => ['boolean'],
        ]);
    }
}
