<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /**
     * account_id is intentionally omitted - Supplier's HasLedgerAccount
     * trait auto-creates and assigns the linked ledger account on save,
     * provided the "Sundry Creditors" subgroup has already been seeded
     * (ChartOfAccountsSeeder, run as part of TenantDatabaseSeeder).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'address' => fake()->address(),
            'mobile_no' => fake()->unique()->numerify('98########'),
            'email' => fake()->unique()->safeEmail(),
            'tpin' => fake()->unique()->numerify('#########'),
        ];
    }
}
