<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `projects.view` / `projects.manage` are new accounting keys, so no existing
 * role holds them. Roles that already post vouchers should not lose the new
 * Projects page and the project field on documents the day this ships:
 *
 *  - projects.view   <- any role with journal_vouchers.view
 *  - projects.manage <- any role with journal_vouchers.create
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

            if (in_array('journal_vouchers.view', $held, true)) {
                $grant[] = 'projects.view';
            }

            if (in_array('journal_vouchers.create', $held, true)) {
                $grant[] = 'projects.manage';
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
