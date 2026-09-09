<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Nullable + a plain unique index - this project's dev DB is SQLite,
     * which allows multiple NULL values under a unique index, so items
     * without a barcode never collide with each other.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('barcode')->nullable()->unique()->after('hs_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique(['barcode']);
            $table->dropColumn('barcode');
        });
    }
};
