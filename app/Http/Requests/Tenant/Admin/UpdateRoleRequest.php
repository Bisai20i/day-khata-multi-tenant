<?php

namespace App\Http\Requests\Tenant\Admin;

use App\Models\Role;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Same permission validation as a new role (see StoreRoleRequest), plus:
 *
 * - `updated_at` is the role's timestamp as the form loaded it. The
 *   controller compares it with the locked row to reject a stale overwrite
 *   (two owner sessions editing the same role). It is nullable because a
 *   role inserted without timestamps has none until its first save.
 * - System roles (`is_system`, e.g. admin) keep their name: the app and the
 *   provisioning templates refer to them, so a rename is refused with 422
 *   rather than ignored.
 *
 * The submitted `permissions` list only ever covers entitled modules (the
 * editor never shows the others); grants the role already holds in modules
 * that are switched off are preserved by the controller, not re-submitted.
 */
class UpdateRoleRequest extends StoreRoleRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $role = $this->editedRole();

        return [
            ...parent::rules(),
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('roles', 'name')->ignore($role),
                function (string $attribute, mixed $value, Closure $fail) use ($role): void {
                    if ($role->is_system && $value !== $role->name) {
                        $fail('The name of a built-in role cannot be changed.');
                    }
                },
            ],
            'updated_at' => ['present', 'nullable', 'date'],
        ];
    }

    public function editedRole(): Role
    {
        /** @var Role $role */
        $role = $this->route('role');

        return $role;
    }
}
