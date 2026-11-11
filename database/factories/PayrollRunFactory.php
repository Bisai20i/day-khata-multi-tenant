<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds a DRAFT run with no lines; posting goes through
 * App\Support\Hr\PayrollPoster so the ledger entries exist.
 *
 * @extends Factory<PayrollRun>
 */
class PayrollRunFactory extends Factory
{
    protected $model = PayrollRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'period_label' => 'Test period',
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'date' => '2026-06-30',
            'status' => PayrollRun::STATUS_DRAFT,
            'branch_id' => fn (): int => Branch::main()->id,
            'created_by' => User::factory(),
        ];
    }
}
