<?php

namespace App\Http\Requests\Tenant\Manufacturing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * More issue notes for an in-progress entry. Authorization is the route's
 * can:production_entries.edit.
 */
class AttachIssueNotesRequest extends FormRequest
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
            'material_issue_note_ids' => ['required', 'array', 'min:1'],
            'material_issue_note_ids.*' => ['integer', 'distinct', 'exists:material_issue_notes,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'material_issue_note_ids.required' => 'Select at least one material issue note to attach.',
            'material_issue_note_ids.min' => 'Select at least one material issue note to attach.',
        ];
    }
}
