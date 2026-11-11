<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the tenant calls a branch on screen (plan 12, section 5.1):
     * branch, site, outlet or project. A plain string with a default, not a
     * native enum (SQLite and MySQL both, no doctrine/dbal); the allowed
     * values are enforced by ActiveBranchSelection::LABELS at the edge.
     *
     * NOT NULL with a default, so every existing row is valid the moment the
     * column exists and no backfill is needed. Guarded so a re-run after a
     * half-applied migration is a no-op.
     */
    public function up(): void
    {
        if (Schema::hasColumn('company_settings', 'branch_label')) {
            return;
        }

        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('branch_label', 20)->default('branch');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('company_settings', 'branch_label')) {
            return;
        }

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('branch_label');
        });
    }
};
