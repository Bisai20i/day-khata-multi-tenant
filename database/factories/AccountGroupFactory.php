<?php

namespace Database\Factories;

use App\Models\AccountGroup;
use App\Models\AccountHead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountGroup>
 */
class AccountGroupFactory extends Factory
{
    protected $model = AccountGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_head_id' => AccountHead::factory(),
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
