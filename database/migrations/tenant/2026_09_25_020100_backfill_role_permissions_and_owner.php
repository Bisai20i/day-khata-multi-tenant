<?php

use App\Support\Permissions\RoleBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Fills roles.permissions / roles.is_system and picks the tenant owner for
     * tenants that existed before the JSON role-permission model. All logic
     * (and the frozen key lists) lives in RoleBackfill so it is unit tested;
     * it is idempotent and never throws for a tenant without an admin.
     *
     * On a brand new tenant this runs before TenantDatabaseSeeder, finds no
     * roles or users, and does nothing; the seeder then creates the roles
     * with their permissions already set.
     */
    public function up(): void
    {
        RoleBackfill::run();
    }

    /**
     * Intentionally a no-op for data. Once released, the rows this migration
     * wrote are indistinguishable from later edits made in the role editor or
     * by an ownership transfer, so "undoing only what it set" cannot be done
     * safely; resetting them could strip a tenant's real configuration. Rolling
     * back one step further (2026_09_25_020000) drops the permissions,
     * is_system and is_owner columns, which removes this data entirely.
     */
    public function down(): void
    {
        //
    }
};
