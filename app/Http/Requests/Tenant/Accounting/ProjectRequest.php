<?php

namespace App\Http\Requests\Tenant\Accounting;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a project. Authorization is the route's
 * can:projects.manage.
 *
 * Money fields stay plain decimal strings end to end: the regex accepts at
 * most 18 integer digits and 2 decimals (the decimal(20, 2) column) and the
 * Decimal cast stores them without any float step. A blank amount means zero.
 */
class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (['code', 'name', 'name_np', 'client', 'notes', 'contract_value', 'budget'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $normalised[$field] = $value === '' ? null : $value;
            }
        }

        $this->merge($normalised);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        $money = ['nullable', 'string', 'regex:/^\d{1,18}(\.\d{1,2})?$/'];

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('projects', 'code')->ignore($project instanceof Project ? $project : null)],
            'name' => ['required', 'string', 'max:255'],
            'name_np' => ['nullable', 'string', 'max:255'],
            'client' => ['nullable', 'string', 'max:255'],
            'contract_value' => $money,
            'budget' => $money,
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => array_merge(['nullable', 'date_format:Y-m-d'], $this->filled('start_date') ? ['after_or_equal:start_date'] : []),
            'status' => ['required', Rule::in(Project::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'A project with this code already exists.',
            'contract_value.regex' => 'Enter an amount with at most 2 decimal places.',
            'budget.regex' => 'Enter an amount with at most 2 decimal places.',
            'end_date.after_or_equal' => 'The end date cannot be before the start date.',
        ];
    }

    /**
     * Validated attributes ready for the model: blank amounts become zero.
     *
     * @return array<string, mixed>
     */
    public function projectAttributes(): array
    {
        $data = $this->validated();
        $data['contract_value'] ??= '0.00';
        $data['budget'] ??= '0.00';

        return $data;
    }
}
