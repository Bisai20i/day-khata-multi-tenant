<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `areas.view` / `areas.manage` are new core keys, so no existing role holds
 * them. Roles that already work with customers or suppliers should not lose
 * the new Area field, filter and master page the day this ships:
 *
 *  - areas.view   <- any role with customers.view or suppliers.view
 *  - areas.manage <- any role with customers.edit or suppliers.edit
 *
 * The source keys and the keys granted are frozen literals (the data-migration
 * rule): this never reads config/permissions.php. Idempotent - a key already
 * present is not added twice, and a role with no stored permissions yet
 * (NULL) is left alone. Written through the query builder so no model events
 * or activity logging fire inside tenants:migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasColumn('roles', 'permissions')) {
            return;
        }

        DB::table('roles')->whereNotNull('permissions')->orderBy('id')->each(function (object $role): void {
            $held = json_decode((string) $role->permissions, true);

            if (! is_array($held)) {
                return;
            }

            $grant = [];

            if (array_intersect(['customers.view', 'suppliers.view'], $held) !== []) {
                $grant[] = 'areas.view';
            }

            if (array_intersect(['customers.edit', 'suppliers.edit'], $held) !== []) {
                $grant[] = 'areas.manage';
            }

            $missing = array_values(array_diff($grant, $held));

            if ($missing === []) {
                return;
            }

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_merge($held, $missing))),
            ]);
        });
    }

    /**
     * Intentionally a no-op: once released, a grant written here is
     * indistinguishable from one an owner made in the role editor, so removing
     * "only ours" cannot be done safely. Dropping the keys from the catalog
     * leaves them dormant.
     */
    public function down(): void
    {
        //
    }
};
