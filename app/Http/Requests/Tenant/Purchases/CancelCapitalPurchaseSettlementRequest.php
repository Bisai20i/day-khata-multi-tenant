<?php

namespace App\Http\Requests\Tenant\Purchases;

use Illuminate\Foundation\Http\FormRequest;

class CancelCapitalPurchaseSettlementRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
