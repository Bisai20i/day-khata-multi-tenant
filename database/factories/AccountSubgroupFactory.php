<?php

namespace Database\Factories;

use App\Models\AccountGroup;
use App\Models\AccountSubgroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountSubgroup>
 */
class AccountSubgroupFactory extends Factory
{
    protected $model = AccountSubgroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_group_id' => AccountGroup::factory(),
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
