<?php

namespace App\Http\Requests\Central;

use App\Models\Tenant;
use App\Support\Branches\BranchModuleGuard;
use App\Support\Permissions\PermissionCatalog;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
    public const BRANCHES_MODULE = 'branches';

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
     * Refuses switching the `branches` module off while the tenant uses more
     * than Main (BranchModuleGuard, plan 12 BR24): that switch-off would hide
     * data and strand goods in transit. Only an actual switch-off is checked
     * (entitled before, not entitled after resolution, which also catches
     * branches being unticked); keeping or enabling the module never reads
     * the tenant database.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $tenant = $this->route('tenant');

                if ($validator->errors()->isNotEmpty() || ! $tenant instanceof Tenant || ! $this->switchesOffBranches($tenant)) {
                    return;
                }

                $blockers = BranchModuleGuard::forTenant($tenant);

                if ($blockers === []) {
                    return;
                }

                $label = PermissionCatalog::modules()[self::BRANCHES_MODULE]['label'] ?? self::BRANCHES_MODULE;

                $validator->errors()->add(
                    'enabled_modules',
                    "{$label} cannot be switched off while this company uses more than Main. ".implode(' ', array_values($blockers)),
                );
            },
        ];
    }

    /**
     * Whether this save takes the branches module away from a tenant that
     * has it today.
     */
    private function switchesOffBranches(Tenant $tenant): bool
    {
        $selected = $this->input('enabled_modules', []);

        if (! is_array($selected)) {
            return false;
        }

        $after = PermissionCatalog::resolveModules(array_values(array_filter($selected, 'is_string')));

        return $tenant->hasModule(self::BRANCHES_MODULE) && ! in_array(self::BRANCHES_MODULE, $after, true);
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
