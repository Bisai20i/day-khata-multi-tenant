<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_category_id' => ItemCategory::factory(),
            'item_subcategory_id' => null,
            'account_id' => null,
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'unit' => fake()->randomElement(['pcs', 'kg', 'ltr', 'box']),
            'hs_code' => fake()->numerify('####.##.##'),
            'min_stock' => fake()->randomFloat(2, 0, 100),
            'is_vatable' => fake()->boolean(),
            'is_stockable' => true,
            'is_active' => true,
        ];
    }
}
