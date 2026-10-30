<?php

namespace Database\Factories;

use App\Models\ProductionEntry;
use App\Models\ProductionWipEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionWipEntry>
 */
class ProductionWipEntryFactory extends Factory
{
    protected $model = ProductionWipEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'production_entry_id' => ProductionEntry::factory(),
            'date' => '2026-06-15',
            'kind' => 'material_in',
            'item_id' => null,
            'quantity' => null,
            'value' => '100.00',
            'direction' => 1,
            'cancelled' => false,
        ];
    }
}
