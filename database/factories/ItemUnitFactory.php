<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemUnit>
 */
class ItemUnitFactory extends Factory
{
    protected $model = ItemUnit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'name' => fake()->unique()->randomElement(['Box', 'Dozen', 'Carton', 'Pack', 'Case']),
            'conversion_factor' => fake()->randomElement([6, 10, 12, 24]),
            'purchase_rate' => null,
            'sale_rate' => null,
            'mrp' => null,
            'is_active' => true,
        ];
    }
}
