<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ItemCategory;
use App\Models\ItemSubcategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ItemSubcategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Inventory/ItemSubcategories/Index', [
            'categories' => ItemCategory::query()->orderBy('name')->get(['id', 'name']),
            'subcategories' => ItemSubcategory::query()->with('category:id,name')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ItemSubcategory::create($this->validated($request));

        return redirect()->route('tenant.item-subcategories.index')->with('status', 'Subcategory added.');
    }

    public function update(Request $request, ItemSubcategory $itemSubcategory): RedirectResponse
    {
        $itemSubcategory->update($this->validated($request, $itemSubcategory));

        return redirect()->route('tenant.item-subcategories.index')->with('status', 'Subcategory updated.');
    }

    public function destroy(ItemSubcategory $itemSubcategory): RedirectResponse
    {
        $itemSubcategory->delete();

        return redirect()->route('tenant.item-subcategories.index')->with('status', 'Subcategory deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?ItemSubcategory $itemSubcategory = null): array
    {
        return $request->validate([
            'item_category_id' => ['required', 'exists:item_categories,id'],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('item_subcategories')->where('item_category_id', $request->item_category_id)->ignore($itemSubcategory),
            ],
            'is_active' => ['boolean'],
        ]);
    }
}
