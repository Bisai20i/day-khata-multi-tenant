<?php

namespace Database\Factories;

use App\Models\ProductionCompletion;
use App\Models\ProductionEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionCompletion>
 */
class ProductionCompletionFactory extends Factory
{
    protected $model = ProductionCompletion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'production_entry_id' => ProductionEntry::factory(),
            'date' => '2026-06-20',
            'quantity' => '10.0000',
            'cost' => '100.00',
            'unit_cost' => '10.0000',
            'is_final' => false,
        ];
    }
}
