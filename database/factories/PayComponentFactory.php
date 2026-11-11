<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\PayComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds a custom (non-system) earning component. The starter Salary, Bonus and
 * Allowance components are seeded with every tenant, so their codes are not
 * used here.
 *
 * @extends Factory<PayComponent>
 */
class PayComponentFactory extends Factory
{
    protected $model = PayComponent::class;

    /**
     * expense_account_id defaults to a fresh account; pass one under the
     * Expenses head when the test cares about where the amounts post.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('COMP????')),
            'name' => fake()->unique()->words(2, true),
            'kind' => PayComponent::KIND_EARNING,
            'expense_account_id' => Account::factory(),
            'is_system' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function system(): static
    {
        return $this->state(fn () => ['is_system' => true]);
    }
}
