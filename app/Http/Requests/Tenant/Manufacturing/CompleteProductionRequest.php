<?php

namespace App\Http\Requests\Tenant\Manufacturing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A partial or final completion. Authorization is the route's
 * can:production_entries.edit. Rules about the remaining planned quantity
 * live in the model, under the entry's row lock.
 */
class CompleteProductionRequest extends FormRequest
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
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'is_final' => ['required', 'boolean'],
        ];
    }
}
