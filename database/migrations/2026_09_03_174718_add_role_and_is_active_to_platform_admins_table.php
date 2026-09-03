<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            // Every existing (and any pre-this-migration) admin defaults to
            // 'owner', so nobody loses access when this ships.
            $table->string('role')->default('owner')->after('email');
            $table->boolean('is_active')->default(true)->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
