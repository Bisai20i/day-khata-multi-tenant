<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * account_id is intentionally omitted - Customer's HasLedgerAccount
     * trait auto-creates and assigns the linked ledger account on save,
     * provided the "Sundry Debtors" subgroup has already been seeded
     * (ChartOfAccountsSeeder, run as part of TenantDatabaseSeeder).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'address' => fake()->address(),
            'mobile_no' => fake()->unique()->numerify('98########'),
            'email' => fake()->unique()->safeEmail(),
            'tpin' => fake()->unique()->numerify('#########'),
            'citizenship' => fake()->numerify('##-##-##-#####'),
        ];
    }
}
