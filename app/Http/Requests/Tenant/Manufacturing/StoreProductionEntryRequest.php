<?php

namespace App\Http\Requests\Tenant\Manufacturing;

use App\Enums\InventoryClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new production entry: header, at least one unattached posted issue note,
 * and optionally the direct costs known up front. Authorization is the
 * route's can:production_entries.create.
 *
 * The note selection is mandatory (MC6) and says so in plain words, rather
 * than the generic "required" text. Whether each note is posted, unattached
 * and from the same store is the model's job inside its transaction.
 */
class StoreProductionEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            // Correction reason: required by the model only when the date
            // falls in a closed year reopened for correction.
            'reason' => ['nullable', 'string', 'max:255'],
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'finished_item_id' => [
                'required',
                'integer',
                Rule::exists('items', 'id')->where('inventory_class', InventoryClass::FinishedGood->value),
            ],
            'planned_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'narration' => ['nullable', 'string', 'max:255'],
            'material_issue_note_ids' => ['required', 'array', 'min:1'],
            'material_issue_note_ids.*' => ['integer', 'distinct', 'exists:material_issue_notes,id'],
            'direct_costs' => ['nullable', 'array'],
        ] + DirectCostRequest::rulesFor('direct_costs.*.');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'material_issue_note_ids.required' => 'Select at least one material issue note: a production entry needs issued material to work on.',
            'material_issue_note_ids.min' => 'Select at least one material issue note: a production entry needs issued material to work on.',
            'finished_item_id.exists' => 'The finished item must be an item classed as a Finished Good.',
        ];
    }
}
