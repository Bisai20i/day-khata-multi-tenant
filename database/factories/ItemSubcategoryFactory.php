<?php

namespace Database\Factories;

use App\Models\ItemCategory;
use App\Models\ItemSubcategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemSubcategory>
 */
class ItemSubcategoryFactory extends Factory
{
    protected $model = ItemSubcategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_category_id' => ItemCategory::factory(),
            'name' => fake()->unique()->words(2, true),
            'is_active' => true,
        ];
    }
}
