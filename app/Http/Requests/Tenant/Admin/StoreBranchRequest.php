<?php

namespace App\Http\Requests\Tenant\Admin;

use App\Models\Branch;
use App\Models\Store;
use App\Support\Branches\BranchContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new branch (plan 12, section 5.1). Authorization is the
 * route's can:branches.manage; BranchController additionally requires the
 * actor to have access to every branch.
 *
 * `code` and `series_code` are stored upper-case and trimmed, so "ktm" and
 * "KTM " cannot become two branches. `series_code` is only printed once a
 * fiscal year uses per-branch numbering (BR20); it is optional until then.
 * `is_main` and `is_active` are never read from this request: Main is fixed
 * and activation has its own actions.
 */
class StoreBranchRequest extends FormRequest
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

    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (['code', 'series_code'] as $field) {
            if (is_string($this->input($field))) {
                $value = strtoupper(trim($this->input($field)));
                $normalised[$field] = $value === '' ? null : $value;
            }
        }

        foreach (['name', 'address', 'phone', 'email', 'pan_number'] as $field) {
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
        $branch = $this->editedBranch();

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('branches', 'name')->ignore($branch)],
            'code' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9-]+$/', Rule::unique('branches', 'code')->ignore($branch)],
            'series_code' => ['nullable', 'string', 'max:6', 'regex:/^[A-Z0-9]+$/', Rule::unique('branches', 'series_code')->ignore($branch)],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'pan_number' => ['nullable', 'string', 'max:20'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'default_store_id' => ['nullable', 'integer', $this->defaultStoreRule($branch)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The code may contain only letters, digits and hyphens.',
            'series_code.regex' => 'The series code may contain only letters and digits.',
        ];
    }

    /**
     * The branch being edited, or null for a new one.
     */
    public function editedBranch(): ?Branch
    {
        $branch = $this->route('branch');

        return $branch instanceof Branch ? $branch : null;
    }

    /**
     * A changed default store must be an active store of this branch. A new
     * branch owns no store yet, so it can only be set after a store is
     * assigned.
     */
    private function defaultStoreRule(?Branch $branch): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($branch): void {
            if ($value === null) {
                return;
            }

            // An unchanged default is not re-checked, so a branch whose
            // default store has since been deactivated can still be edited.
            if ($branch !== null && $branch->default_store_id !== null && (int) $value === (int) $branch->default_store_id) {
                return;
            }

            $store = Store::query()->whereKey($value)->first(['id', 'branch_id', 'is_active']);

            if ($store === null) {
                $fail('The selected store does not exist.');

                return;
            }

            if ($branch === null || (int) $store->branch_id !== (int) $branch->getKey()) {
                $fail('The default store must be one of this branch\'s stores.');

                return;
            }

            if (! $store->is_active) {
                $fail('The default store must be an active store.');
            }
        };
    }
}
