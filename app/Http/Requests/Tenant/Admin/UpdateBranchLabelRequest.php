<?php

namespace App\Http\Requests\Tenant\Admin;

use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What the company calls a branch on screen (company_settings.branch_label,
 * plan 12, section 5.1). Authorization is the route's can:branches.manage.
 * The allowed words are ActiveBranchSelection::LABELS, the one list the
 * shared `branchLabel` prop also reads.
 */
class UpdateBranchLabelRequest extends FormRequest
{
    /**
     * Branch administration is company-wide: a user limited to some branches
     * gets 403 before validation, even with branches.manage (BranchController
     * repeats the check as defence in depth).
     */
    public function authorize(): bool
    {
        return BranchContext::allowedBranchIds($this->user()) === null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'branch_label' => ['required', 'string', Rule::in(ActiveBranchSelection::LABELS)],
        ];
    }
}
