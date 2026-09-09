<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Optional brand tag on an item - see create_brands_table's docblock.
     * Nullable and nullOnDelete (unlike item_category_id's restrictOnDelete):
     * a brand is an optional classification, not a required one, so deleting
     * a brand should simply un-tag its items rather than being blocked or
     * cascading a delete.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('item_subcategory_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
        });
    }
};
