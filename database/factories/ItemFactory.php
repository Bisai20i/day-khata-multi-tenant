<?php

namespace Database\Factories;

use App\Enums\InventoryClass;
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
            'brand_id' => null,
            'account_id' => null,
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'unit' => fake()->randomElement(['pcs', 'kg', 'ltr', 'box']),
            'hs_code' => fake()->numerify('####.##.##'),
            'min_stock' => fake()->randomFloat(2, 0, 100),
            'purchase_rate' => fake()->randomFloat(2, 10, 500),
            'sale_rate' => fake()->randomFloat(2, 10, 500),
            'image_path' => null,
            'is_vatable' => fake()->boolean(),
            'is_stockable' => true,
            'is_active' => true,
        ];
    }

    /**
     * A perpetual raw material item (posts to AS12).
     */
    public function rawMaterial(): static
    {
        return $this->state(fn () => ['inventory_class' => InventoryClass::RawMaterial, 'is_stockable' => true]);
    }

    /**
     * A perpetual finished good item (posts to AS14).
     */
    public function finishedGood(): static
    {
        return $this->state(fn () => ['inventory_class' => InventoryClass::FinishedGood, 'is_stockable' => true]);
    }
}
