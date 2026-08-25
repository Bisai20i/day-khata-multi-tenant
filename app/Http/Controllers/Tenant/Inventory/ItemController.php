<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemSubcategory;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ItemController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Inventory/Items/Index', [
            'categories' => ItemCategory::query()->orderBy('name')->get(['id', 'name']),
            'subcategories' => ItemSubcategory::query()->orderBy('name')->get(['id', 'item_category_id', 'name']),
            'items' => Item::query()->with(['category:id,name', 'subcategory:id,name'])->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Item::create($this->validated($request));

        return redirect()->route('tenant.items.index')->with('status', 'Item added.');
    }

    public function update(Request $request, Item $item): RedirectResponse
    {
        $item->update($this->validated($request));

        return redirect()->route('tenant.items.index')->with('status', 'Item updated.');
    }

    public function destroy(Item $item): RedirectResponse
    {
        $item->delete();

        return redirect()->route('tenant.items.index')->with('status', 'Item deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'item_category_id' => ['required', 'exists:item_categories,id'],
            'item_subcategory_id' => [
                'nullable', 'exists:item_subcategories,id',
                function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                    if ($value && ItemSubcategory::find($value)?->item_category_id !== (int) $request->item_category_id) {
                        $fail('The selected subcategory does not belong to the selected category.');
                    }
                },
            ],
            'account_id' => ['nullable', 'exists:accounts,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'unit' => ['required', 'string', 'max:50'],
            'hs_code' => ['nullable', 'string', 'max:30'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'is_vatable' => ['boolean'],
            'is_stockable' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }
}
