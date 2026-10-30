<?php

namespace App\Http\Requests\Tenant\Manufacturing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The mandatory reason behind an abandon, a cancel, or the cancel of a
 * direct cost, a completion or a material return.
 * Authorization is the route's can: gate.
 */
class ProductionReasonRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
