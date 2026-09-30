<?php

namespace App\Http\Requests\Tenant\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Handing over a company is irreversible for the person doing it (only the
 * new owner can hand it back), so the route's can:ownership.transfer gate
 * (owner-only) is not enough on its own: the owner re-enters their password,
 * which keeps an unattended signed-in browser from giving the company away.
 *
 * current_password:web checks the value against the signed-in user of the
 * tenant 'web' guard, the same rule ProfileController uses. Authorization
 * stays with the route gate, so authorize() only returns true.
 */
class TransferOwnershipRequest extends FormRequest
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
            'current_password' => ['required', 'string', 'current_password:web'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'That password is not correct.',
        ];
    }
}
