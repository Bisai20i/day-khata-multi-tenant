<?php

namespace App\Http\Requests\Central;

use App\Support\Permissions\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a central "which modules may this tenant use" selection.
 *
 * Who may send it is decided by the route (auth:platform, any platform
 * admin), matching the plain tenant update() action: module entitlements are
 * routine account support, unlike trial expiry or deletion which are
 * deliberately platform-owner only.
 *
 * The client's ticks are only a wish list. Unknown keys are rejected (422)
 * rather than silently dropped, so a typo or a stale form never quietly
 * narrows a tenant's access, and the stored value is always rebuilt
 * server-side by canonicalModules(): requirements are added here, never
 * trusted from the checkbox UI.
 */
class UpdateTenantModulesRequest extends FormRequest
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
        return self::moduleRules(required: true);
    }

    /**
     * Shared with TenantController::store() so create and later edits accept
     * exactly the same payload. `present` rather than `required` on update
     * because an empty list is a real choice (core only), and `required`
     * fails an empty array. On create the field is optional: absent means
     * the default module set, which keeps older callers and tests working.
     * `core` (any always_on module) is accepted but has no effect: it is on
     * regardless, so the UI can send it back as a disabled, ticked box.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function moduleRules(bool $required): array
    {
        return [
            'enabled_modules' => [$required ? 'present' : 'sometimes', 'array', 'list'],
            'enabled_modules.*' => ['string', 'distinct', Rule::in(array_keys(PermissionCatalog::modules()))],
        ];
    }

    /**
     * The one storage form for `tenants.enabled_modules`: the dependency
     * closure of the selection, minus always_on modules, in config order.
     *
     * Storing the resolved list (rather than the raw ticks) makes the column
     * read the same as what the tenant can actually use, so the audit log's
     * before/after and any future report on the column need no resolution
     * step. always_on modules are left out because they are granted by
     * resolveModules() on every read anyway, and storing them would suggest
     * they could be switched off. Config order makes equal selections compare
     * equal, which is how an unchanged save is detected.
     *
     * @param  list<string>  $selected
     * @return list<string>
     */
    public static function canonicalModules(array $selected): array
    {
        $modules = PermissionCatalog::modules();

        return array_values(array_filter(
            PermissionCatalog::resolveModules($selected),
            fn (string $key): bool => ! $modules[$key]['always_on'],
        ));
    }

    /**
     * The validated selection in its canonical storage form.
     *
     * @return list<string>
     */
    public function canonicalSelection(): array
    {
        return self::canonicalModules($this->validated('enabled_modules', []));
    }
}
