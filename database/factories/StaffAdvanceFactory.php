<?php

namespace Database\Factories;

use App\Models\StaffAdvance;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds the advance row only, with no ledger entries. fiscal_year_id and
 * journal_voucher_id are NOT NULL and have no factories (a voucher must balance
 * and belong to a fiscal year), so callers pass them in. To get a real posted
 * advance, go through App\Support\Hr\StaffAdvancePoster instead.
 *
 * @extends Factory<StaffAdvance>
 */
class StaffAdvanceFactory extends Factory
{
    protected $model = StaffAdvance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_id' => StaffMember::factory(),
            'date' => '2026-06-10',
            'amount' => '3000.00',
            'payment_mode' => 'cash',
            'status' => 'posted',
            // Follows the staff member's home branch, like StaffAdvancePoster.
            'branch_id' => fn (array $attributes): int => (int) StaffMember::query()->whereKey($attributes['staff_id'])->value('branch_id'),
            'created_by' => User::factory(),
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => User::factory(),
            'cancel_reason' => 'Entered by mistake',
        ]);
    }
}
