<?php

namespace Database\Factories;

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\FiscalYearInventorySnapshot;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalYearInventorySnapshot>
 */
class FiscalYearInventorySnapshotFactory extends Factory
{
    protected $model = FiscalYearInventorySnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fiscal_year_id' => fn () => FiscalYear::query()->latest('id')->value('id') ?? FiscalYear::create(['name' => 'FY '.fake()->unique()->year(), 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open])->id,
            'inventory_class' => 'raw_material',
            'item_id' => Item::factory()->rawMaterial(),
            'production_entry_id' => null,
            'quantity' => '0.0000',
            'value' => '0.00',
        ];
    }
}
