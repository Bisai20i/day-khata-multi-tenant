<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces the three independent sale_*_enabled toggles with one fixed
     * invoice type per tenant. IRD requires a business to issue exactly the
     * invoice type it is registered under, decided by the platform admin -
     * never a per-sale cashier choice (see App\Models\Sale::post(), which
     * used to trust a client-submitted invoice_type as long as it was one of
     * however many types happened to be left enabled).
     *
     * Nullable, so no ->change()/doctrine-dbal is needed and every existing
     * tenant row stays valid before the backfill below runs.
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('active_invoice_type')->nullable()->after('sale_pan_enabled');
        });

        // Exactly one company_settings row per tenant, so this is one cheap
        // read/write, not a batch job. Prefers full > abbreviated > pan when
        // more than one flag was left on, since full tax invoice is the
        // strictest/most broadly valid format - nobody's tenant silently
        // loses the ability to invoice.
        $row = DB::table('company_settings')->first();

        if ($row) {
            $activeType = match (true) {
                (bool) $row->sale_full_enabled => 'full',
                (bool) $row->sale_abbreviated_enabled => 'abbreviated',
                (bool) $row->sale_pan_enabled => 'pan',
                default => 'full',
            };

            DB::table('company_settings')->update(['active_invoice_type' => $activeType]);
        }

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['sale_full_enabled', 'sale_abbreviated_enabled', 'sale_pan_enabled']);
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('sale_full_enabled')->default(true)->after('sale_full_prefix');
            $table->boolean('sale_abbreviated_enabled')->default(true)->after('sale_abbreviated_prefix');
            $table->boolean('sale_pan_enabled')->default(true)->after('sale_pan_prefix');
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('active_invoice_type');
        });
    }
};
