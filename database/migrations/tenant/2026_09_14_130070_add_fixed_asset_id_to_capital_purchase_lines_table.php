<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a capital purchase line to the FixedAsset it optionally created
 * (CapitalPurchase::post(), item 5 of this task). Nullable: most capital
 * purchase lines never create an asset (a "service" purchase, or a small
 * capital item the tenant does not want to track/depreciate individually).
 * `nullOnDelete` rather than restrict - deleting a FixedAsset (which nothing
 * in this app currently does; there is no destroy() on it) should not be
 * blocked by a line that only ever pointed at it for traceability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capital_purchase_lines', function (Blueprint $table) {
            $table->foreignId('fixed_asset_id')->nullable()->after('account_id')->constrained('fixed_assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('capital_purchase_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fixed_asset_id');
        });
    }
};
