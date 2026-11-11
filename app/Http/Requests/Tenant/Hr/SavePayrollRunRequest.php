<?php

namespace App\Http\Requests\Tenant\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Header and lines of a draft payroll run (the whole draft is replaced on
 * save). Authorization is the route's can:payroll.create. Whether a recovery is
 * within the staff member's outstanding advance, and whether a staff member is
 * already in another run, is PayrollDraft's job under its transaction.
 */
class SavePayrollRunRequest extends FormRequest
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
            'period_label' => ['required', 'string', 'max:100'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'date' => ['required', 'date'],
            'narration' => ['nullable', 'string', 'max:200'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.staff_id' => ['required', 'integer', 'distinct', 'exists:hr_staff,id'],
            'lines.*.advance_recovery' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999999999.99'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
            'lines.*.items' => ['required', 'array', 'min:1'],
            'lines.*.items.*.pay_component_id' => ['required', 'integer', Rule::exists('hr_pay_components', 'id')->where('is_active', true)],
            'lines.*.items.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999999999999.99'],
            'lines.*.items.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'A payroll run needs at least one staff member.',
            'lines.min' => 'A payroll run needs at least one staff member.',
            'lines.*.items.required' => 'Every staff member needs at least one pay amount.',
            'lines.*.items.min' => 'Every staff member needs at least one pay amount.',
        ];
    }
}
