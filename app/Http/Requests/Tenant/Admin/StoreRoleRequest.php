<?php

namespace App\Http\Requests\Tenant\Admin;

use App\Support\Permissions\PermissionCatalog;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new role. Authorization is the route's can:roles.manage
 * (owner-only), so authorize() only returns true.
 *
 * Every submitted permission key is re-validated server-side against the
 * catalog and the tenant's CURRENT entitlements (plan section 5): a crafted
 * request carrying an unknown key, an owner-only key or a key from a module
 * this company is not entitled to fails with 422 naming the key. Nothing is
 * silently dropped, because a silently dropped grant looks saved to the owner
 * while the employee still cannot do the job.
 */
class StoreRoleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')],
            // present (not required): an empty role is legitimate, but a
            // client that forgot the field must not be read as "grant nothing".
            'permissions' => ['present', 'array'],
            'permissions.*' => ['bail', 'string', 'distinct', $this->grantablePermissionRule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'permissions.*.distinct' => 'The permission ":input" is listed more than once.',
            'permissions.*.string' => 'Every permission must be a permission key.',
        ];
    }

    /**
     * The submitted keys, validated, in catalog order (the editor's display
     * order), so the stored JSON is deterministic whatever order the client
     * ticked the boxes in.
     *
     * @return list<string>
     */
    public function permissionKeys(): array
    {
        $submitted = array_flip(array_map('strval', (array) $this->validated('permissions', [])));

        return array_values(array_filter(
            array_keys(PermissionCatalog::permissions()),
            fn (string $key): bool => isset($submitted[$key]),
        ));
    }

    /**
     * One key: known to the catalog, not owner-only, and in a module the
     * tenant is entitled to right now. Entitlements are resolved once per
     * request (tenant row already loaded by the tenancy middleware, so this
     * costs no query).
     */
    protected function grantablePermissionRule(): Closure
    {
        $entitledModules = tenant()?->entitledModules() ?? [];

        return function (string $attribute, mixed $value, Closure $fail) use ($entitledModules): void {
            $key = (string) $value;

            if (! PermissionCatalog::has($key)) {
                $fail("\"{$key}\" is not a known permission.");

                return;
            }

            if (PermissionCatalog::isOwnerOnly($key)) {
                $fail("\"{$key}\" is reserved for the company owner and cannot be granted to a role.");

                return;
            }

            if (! in_array(PermissionCatalog::moduleOf($key), $entitledModules, true)) {
                $fail("\"{$key}\" belongs to a module that is not enabled for this company.");
            }
        };
    }
}
