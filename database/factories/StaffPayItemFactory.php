<?php

namespace Database\Factories;

use App\Models\PayComponent;
use App\Models\StaffMember;
use App\Models\StaffPayItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffPayItem>
 */
class StaffPayItemFactory extends Factory
{
    protected $model = StaffPayItem::class;

    /**
     * Defaults to the seeded Salary component; pass pay_component_id to use
     * another (the starter three are seeded with every tenant).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_id' => StaffMember::factory(),
            'pay_component_id' => fn () => PayComponent::where('code', 'SALARY')->value('id'),
            'amount' => '30000.00',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ];
    }
}
