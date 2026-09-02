<?php

namespace Database\Factories;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    protected $model = Agent::class;

    /**
     * account_id is intentionally omitted - Agent's HasLedgerAccount trait
     * auto-creates and assigns the linked ledger account on save, provided
     * the "Sales Agents" subgroup has already been seeded
     * (ChartOfAccountsSeeder, run as part of TenantDatabaseSeeder).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'mobile_no' => fake()->unique()->numerify('98########'),
            'address' => fake()->address(),
            'commission_rate' => fake()->randomFloat(2, 1, 10),
            'is_active' => true,
        ];
    }
}
