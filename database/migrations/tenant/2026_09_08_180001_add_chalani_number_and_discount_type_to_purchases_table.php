<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Symmetric with the sales-table migration in this same batch (see its
     * docblock) - chalani_number and discount_type added to purchases too,
     * deliberately unlike legacy's sales-only/inconsistent treatment.
     */
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('chalani_number')->nullable()->after('pan_number');
            $table->string('discount_type')->default('flat')->after('discount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['chalani_number', 'discount_type']);
        });
    }
};
