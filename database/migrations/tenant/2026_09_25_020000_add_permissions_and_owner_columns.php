<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the columns behind the JSON role-permission model: a role's grants
     * live in roles.permissions (JSON list of keys), is_system marks roles the
     * app itself depends on, and users.is_owner marks the single tenant owner.
     *
     * The old permissions / permission_role tables are intentionally left in
     * place; a later release drops them. Each column is guarded so a re-run
     * (or a partially applied tenant) is harmless. NOT NULL columns carry a
     * default, so existing rows are valid without a separate backfill.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_owner')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_owner')->default(false);
            });
        }

        if (! Schema::hasColumn('roles', 'permissions')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->json('permissions')->nullable();
            });
        }

        if (! Schema::hasColumn('roles', 'is_system')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->boolean('is_system')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('roles', 'is_system')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('is_system');
            });
        }

        if (Schema::hasColumn('roles', 'permissions')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('permissions');
            });
        }

        if (Schema::hasColumn('users', 'is_owner')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_owner');
            });
        }
    }
};
