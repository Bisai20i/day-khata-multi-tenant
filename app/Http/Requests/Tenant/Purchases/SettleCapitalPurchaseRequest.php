<?php

namespace App\Http\Requests\Tenant\Purchases;

use App\Support\Branches\BranchAccounts;
use Illuminate\Foundation\Http\FormRequest;

class SettleCapitalPurchaseRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'payment_mode' => ['required', 'in:cash,bank'],
            // Never an inter-branch control account (BranchAccounts).
            'bank_account_id' => ['nullable', 'required_if:payment_mode,bank', 'exists:accounts,id', BranchAccounts::notControlAccountRule()],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'narration' => ['nullable', 'string', 'max:255'],
            // Where the money went out (plan 12, BR07); the model checks it
            // is active and accessible, default the posting branch.
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ];
    }
}
