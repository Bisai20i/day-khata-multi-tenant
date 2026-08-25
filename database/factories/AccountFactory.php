<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AccountSubgroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * Defaults to a subgroup-filed account, since Account::booted() requires
     * exactly one of account_group_id/account_subgroup_id to be set.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_group_id' => null,
            'account_subgroup_id' => AccountSubgroup::factory(),
            'code' => fake()->unique()->bothify('AC-####'),
            'name' => fake()->unique()->company(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
        ];
    }

    /**
     * File this account directly under a group instead of a subgroup.
     */
    public function underGroup(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_group_id' => AccountGroup::factory(),
            'account_subgroup_id' => null,
        ]);
    }
}
