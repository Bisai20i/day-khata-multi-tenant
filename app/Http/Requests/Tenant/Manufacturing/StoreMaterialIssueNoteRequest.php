<?php

namespace App\Http\Requests\Tenant\Manufacturing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation only. Whether an item is a raw material, whether the store
 * holds enough of it and whether the date is in an open fiscal year are the
 * model's job (MaterialIssueNote::post()), under the item locks.
 */
class StoreMaterialIssueNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            // Correction reason: required by the model only when the date
            // falls in a closed year reopened for correction.
            'reason' => ['nullable', 'string', 'max:255'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'production_entry_id' => ['nullable', 'integer', 'exists:production_entries,id'],
            'narration' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.item_unit_id' => ['nullable', 'integer', 'exists:item_units,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001', 'decimal:0,4'],
        ];
    }
}
