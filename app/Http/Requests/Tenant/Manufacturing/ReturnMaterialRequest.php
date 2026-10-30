<?php

namespace App\Http\Requests\Tenant\Manufacturing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Unused material going back from WIP to the store. Authorization is the
 * route's can:production_entries.edit. The over-return limit (the item's WIP
 * quantity on this entry) is enforced by the model under its row lock.
 */
class ReturnMaterialRequest extends FormRequest
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
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'date' => ['required', 'date'],
            // Correction reason: required by the model only when the date
            // falls in a closed year reopened for correction.
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
