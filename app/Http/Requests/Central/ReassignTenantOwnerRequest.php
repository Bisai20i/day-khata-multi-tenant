<?php

namespace App\Http\Requests\Central;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a central "make this user the tenant's owner" request.
 *
 * Who may send it is decided by the route (auth:platform plus
 * can:platform-owner). Whether the user exists in that tenant, is active and
 * is not already the owner depends on the tenant database, so the controller
 * checks those inside the tenant context and reports them on `user_id`.
 */
class ReassignTenantOwnerRequest extends FormRequest
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
            'user_id' => ['required', 'integer'],
        ];
    }
}
