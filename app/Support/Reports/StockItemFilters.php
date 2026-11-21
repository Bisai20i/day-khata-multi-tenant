<?php

namespace App\Support\Reports;

use App\Models\Brand;
use App\Models\ItemCategory;
use App\Models\ItemSubcategory;
use Illuminate\Http\Request;

/**
 * The category / sub-category / brand / item-name filters the stock reports
 * share (legacy: group, sub-group, brand, item). One place validates them,
 * turns them into the `$filters` array StockCosting::valuationRows() already
 * understands, and lists the select options, so every stock report filters
 * the same way.
 */
final class StockItemFilters
{
    /**
     * Validation rules for the four filter inputs. Merge into a controller's
     * own `$request->validate([...])`.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'item_category_id' => ['nullable', 'integer', 'exists:item_categories,id'],
            'item_subcategory_id' => ['nullable', 'integer', 'exists:item_subcategories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * The applied filters as StockCosting::valuationRows() keys. Absent or
     * empty inputs are omitted, never passed as null/empty.
     *
     * @return array<string, int|string>
     */
    public static function fromRequest(Request $request): array
    {
        $filters = [];

        foreach (['item_category_id', 'item_subcategory_id', 'brand_id'] as $key) {
            if ($request->integer($key) > 0) {
                $filters[$key] = $request->integer($key);
            }
        }

        $search = trim($request->string('search')->toString());

        if ($search !== '') {
            $filters['search'] = $search;
        }

        return $filters;
    }

    /**
     * Select options for the filter bar.
     *
     * @return array{categories: list<array{id: int, name: string}>, subcategories: list<array{id: int, name: string, item_category_id: int}>, brands: list<array{id: int, name: string}>}
     */
    public static function options(): array
    {
        return [
            'categories' => ItemCategory::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (ItemCategory $category) => ['id' => $category->id, 'name' => $category->name])->all(),
            'subcategories' => ItemSubcategory::query()->orderBy('name')->get(['id', 'name', 'item_category_id'])
                ->map(fn (ItemSubcategory $sub) => ['id' => $sub->id, 'name' => $sub->name, 'item_category_id' => $sub->item_category_id])->all(),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Brand $brand) => ['id' => $brand->id, 'name' => $brand->name])->all(),
        ];
    }
}
