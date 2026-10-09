<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ItemCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ItemCategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Inventory/ItemCategories/Index', [
            'categories' => ItemCategory::query()->orderBy('name')->get(),
        ]);
    }

    /**
     * A `quick_add` request comes from another page's form (the "+ New
     * category" shortcut on the item and subcategory forms), so it returns
     * there instead of landing on the categories list.
     */
    public function store(Request $request): RedirectResponse
    {
        ItemCategory::create($this->validated($request));

        $redirect = $request->boolean('quick_add') ? back() : redirect()->route('tenant.item-categories.index');

        return $redirect->with('status', 'Category added.');
    }

    public function update(Request $request, ItemCategory $itemCategory): RedirectResponse
    {
        $itemCategory->update($this->validated($request, $itemCategory));

        return redirect()->route('tenant.item-categories.index')->with('status', 'Category updated.');
    }

    public function destroy(ItemCategory $itemCategory): RedirectResponse
    {
        $itemCategory->delete();

        return redirect()->route('tenant.item-categories.index')->with('status', 'Category deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?ItemCategory $itemCategory = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('item_categories')->ignore($itemCategory)],
            'is_active' => ['boolean'],
        ]);
    }
}
