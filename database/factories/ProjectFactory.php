<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('PRJ-####')),
            'name' => fake()->unique()->company().' Project',
            'name_np' => null,
            'client' => fake()->company(),
            'contract_value' => '5000000.00',
            'budget' => '4000000.00',
            'start_date' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'end_date' => fake()->dateTimeBetween('+1 month', '+1 year')->format('Y-m-d'),
            'status' => Project::STATUS_ACTIVE,
            'notes' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => ['status' => Project::STATUS_ACTIVE]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => Project::STATUS_COMPLETED]);
    }

    public function onHold(): static
    {
        return $this->state(fn (array $attributes) => ['status' => Project::STATUS_ON_HOLD]);
    }
}
