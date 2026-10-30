<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\MaterialIssueItem;
use App\Models\MaterialIssueNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialIssueItem>
 */
class MaterialIssueItemFactory extends Factory
{
    protected $model = MaterialIssueItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'material_issue_note_id' => MaterialIssueNote::factory(),
            'item_id' => Item::factory()->rawMaterial(),
            'item_unit_id' => null,
            'quantity' => '1.0000',
            'base_quantity' => '1.0000',
            'unit_cost_rate' => '0.0000',
            'line_cost' => '0.00',
        ];
    }
}
