<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\StaffMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffMember>
 */
class StaffMemberFactory extends Factory
{
    protected $model = StaffMember::class;

    /**
     * staff_code is omitted: StaffMember generates STF-0001... on create.
     * The home branch is Main unless the caller passes another branch_id.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'mobile_no' => fake()->unique()->numerify('98########'),
            'address' => fake()->address(),
            'designation' => fake()->jobTitle(),
            'department' => fake()->randomElement(['Sales', 'Accounts', 'Store', 'Production']),
            'join_date' => '2026-01-01',
            'is_active' => true,
            'branch_id' => fn (): int => Branch::main()->id,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
