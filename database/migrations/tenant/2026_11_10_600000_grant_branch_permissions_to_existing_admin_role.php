<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 12 BR24: the Admin role of an EXISTING tenant gets the branch keys.
 *
 * A new tenant's Admin is seeded with every grantable key
 * (TenantDatabaseSeeder: PermissionCatalog::grantable()), so it already holds
 * these. An existing tenant's Admin holds the frozen JSON list it was given
 * at its own rollout, which predates the `branches` module, so the day the
 * central panel switches the module on, its Admin would see nothing until
 * the owner edited the role. This closes that gap so the two stay the same.
 *
 * Only the role with slug `admin` is touched; Manager, Cashier and custom
 * roles are left for the owner (RoleTemplates covers new tenants' Manager).
 * The keys stay dormant while the module is off: the entitlement gate hides
 * them, so granting them changes nothing for a tenant without branches.
 *
 * The key list is a frozen literal (the data-migration rule): this never
 * reads config/permissions.php. Idempotent - a key already present is not
 * added twice, a missing Admin role or one with no stored permissions yet
 * (NULL, which RoleBackfill fills later) is left alone. Written through the
 * query builder so no model events or activity logging fire inside
 * tenants:migrate.
 */
return new class extends Migration
{
    /**
     * Every `branches` module key in the catalog at release.
     *
     * @var list<string>
     */
    private const KEYS = [
        'stock_transfers.receive',
        'branches.view',
        'branches.manage',
        'branches.report',
    ];

    public function up(): void
    {
        $this->eachAdminRole(function (object $role, array $held): void {
            $missing = array_values(array_diff(self::KEYS, $held));

            if ($missing === []) {
                return;
            }

            $this->store($role, array_values(array_merge($held, $missing)));
        });
    }

    /**
     * Removes these keys from the Admin role only. Whether up() or the owner
     * added a key is not recorded, so this takes them all off Admin; every
     * other role, and every other key on Admin, is left as it is.
     */
    public function down(): void
    {
        $this->eachAdminRole(function (object $role, array $held): void {
            $kept = array_values(array_diff($held, self::KEYS));

            if (count($kept) === count($held)) {
                return;
            }

            $this->store($role, $kept);
        });
    }

    /**
     * @param  Closure(object, list<mixed>): void  $callback
     */
    private function eachAdminRole(Closure $callback): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasColumn('roles', 'permissions')) {
            return;
        }

        DB::table('roles')
            ->where('slug', 'admin')
            ->whereNotNull('permissions')
            ->orderBy('id')
            ->each(function (object $role) use ($callback): void {
                $held = json_decode((string) $role->permissions, true);

                if (! is_array($held)) {
                    return;
                }

                $callback($role, array_values($held));
            });
    }

    /**
     * @param  list<string>  $permissions
     */
    private function store(object $role, array $permissions): void
    {
        DB::table('roles')->where('id', $role->id)->update([
            'permissions' => json_encode($permissions),
        ]);
    }
};
