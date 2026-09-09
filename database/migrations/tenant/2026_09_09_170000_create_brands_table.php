<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Item brand/manufacturer master data - the multi-tenant equivalent of
     * legacy day_khata's `companies` table (its misleadingly-named
     * CompanyController; the UI itself labelled this "Add Item Brand").
     * Independent of ItemCategory - a brand is a manufacturer tag, not a
     * classification - and independent of CompanySetting, which is the
     * tenant's own business profile, not item master data.
     */
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
