<?php

namespace App\Http\Requests\Tenant\Accounting;

use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per-branch numbering scope of one fiscal year (plan 12, BR20). Owner only:
 * anyone else gets 403 before validation. NumberingScope::change() repeats
 * the owner check and enforces every other rule (module on, year not yet
 * closed, no vouchers, series codes) under the fiscal year lock.
 */
class UpdateNumberingScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isOwner();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'numbering_scope' => ['required', 'string', Rule::in([FiscalYear::NUMBERING_SHARED, FiscalYear::NUMBERING_PER_BRANCH])],
        ];
    }
}
