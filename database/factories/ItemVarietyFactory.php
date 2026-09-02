<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemVariety;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemVariety>
 */
class ItemVarietyFactory extends Factory
{
    protected $model = ItemVariety::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'name' => fake()->unique()->words(2, true),
            'sku_suffix' => fake()->optional()->bothify('??-###'),
            'price_adjustment' => fake()->randomFloat(2, -50, 50),
            'is_active' => true,
        ];
    }
}
