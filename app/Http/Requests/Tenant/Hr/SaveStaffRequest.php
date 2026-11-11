<?php

namespace App\Http\Requests\Tenant\Hr;

use App\Models\StaffMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and update of a staff member and their recurring pay items.
 * Authorization is the route's can:hr_staff.create / hr_staff.edit.
 *
 * `pay_items` is the DESIRED set of recurring entitlements as of
 * `pay_effective_from`; StaffPayItemSync turns the difference into closed and
 * opened rows so history is kept.
 */
class SaveStaffRequest extends FormRequest
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
        /** @var StaffMember|null $staff */
        $staff = $this->route('staff');

        // A component deactivated after it was given to this staff member stays
        // in their open pay items; keep accepting it so the form can be saved.
        $heldComponentIds = $staff
            ? $staff->payItems()->whereNull('effective_to')->pluck('pay_component_id')->all()
            : [];

        return [
            'staff_code' => ['nullable', 'string', 'max:50', Rule::unique('hr_staff', 'staff_code')->ignore($staff?->id)],
            'name' => ['required', 'string', 'max:255'],
            'mobile_no' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'join_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:join_date'],
            'pan_no' => ['nullable', 'string', 'max:50'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_no' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', Rule::unique('hr_staff', 'user_id')->ignore($staff?->id)],
            'is_active' => ['sometimes', 'boolean'],
            // Home branch (plan 12, BR09). Existence, active and access are
            // checked by StaffController through DocumentBranch, which knows
            // the actor; missing means the actor's posting branch on create
            // and "unchanged" on update.
            'branch_id' => ['sometimes', 'nullable', 'integer'],
            'pay_effective_from' => ['required_with:pay_items', 'nullable', 'date'],
            'pay_items' => ['nullable', 'array'],
            'pay_items.*.pay_component_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('hr_pay_components', 'id')->where(fn ($query) => $query->where(
                    fn ($inner) => $inner->where('is_active', true)->orWhereIn('id', $heldComponentIds),
                )),
            ],
            'pay_items.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pay_effective_from.required_with' => 'Say from which date these pay amounts apply.',
            'pay_items.*.pay_component_id.distinct' => 'Each pay component can be listed only once.',
        ];
    }
}
