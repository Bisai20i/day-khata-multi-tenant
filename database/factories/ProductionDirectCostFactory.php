<?php

namespace Database\Factories;

use App\Enums\DirectCostType;
use App\Models\Account;
use App\Models\ProductionDirectCost;
use App\Models\ProductionEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionDirectCost>
 */
class ProductionDirectCostFactory extends Factory
{
    protected $model = ProductionDirectCost::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'production_entry_id' => ProductionEntry::factory(),
            'date' => '2026-06-15',
            'cost_type' => DirectCostType::Labor,
            'description' => fake()->sentence(3),
            'amount' => '100.00',
            'credit_account_id' => Account::factory(),
        ];
    }
}
