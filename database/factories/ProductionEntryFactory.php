<?php

namespace Database\Factories;

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ProductionEntry;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionEntry>
 */
class ProductionEntryFactory extends Factory
{
    protected $model = ProductionEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fiscal_year_id' => fn () => FiscalYear::query()->latest('id')->value('id') ?? FiscalYear::create(['name' => 'FY '.fake()->unique()->year(), 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open])->id,
            'number' => fake()->unique()->numberBetween(1, 999999),
            'date' => '2026-06-15',
            'store_id' => Store::factory(),
            'finished_item_id' => Item::factory()->finishedGood(),
            'planned_quantity' => '100.0000',
            'status' => 'in_progress',
            'narration' => null,
            'created_by' => User::factory(),
        ];
    }
}
