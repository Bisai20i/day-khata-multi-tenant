<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A linked return gives back its share of the original bill's Round Off,
     * the same way it gives back its share of the VAT and TDS: the bill's
     * Round Off is split across its lines by net value, and every return
     * line records the slice it handed back, so the return that takes a
     * line's last unit can be given exactly "what is left" and no paisa is
     * stranded or invented.
     *
     * - `sales_returns.round_off` / `purchase_returns.round_off`: the signed
     *   Round Off the document reverses; already INCLUDED in its `total`.
     * - `sale_return_lines.round_off` / `purchase_return_lines.round_off`:
     *   the per-line slice, summed to know what earlier returns took.
     * - `purchase_lines.round_off`: purchases store their per-line components
     *   at posting (net_value, vat_amount, tds_amount), so the Round Off
     *   slice is stored there too. Sale lines compute theirs on demand.
     *
     * Existing rows default to 0: no bill or return posted before Round Off
     * existed carries any.
     */
    private const TABLES = [
        'sales_returns' => 'total',
        'sale_return_lines' => 'tds_amount',
        'purchase_returns' => 'total',
        'purchase_return_lines' => 'tds_amount',
        'purchase_lines' => 'tds_amount',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName => $after) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'round_off')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $after) {
                $column = $table->decimal('round_off', 15, 2)->default(0);

                if (Schema::hasColumn($tableName, $after)) {
                    $column->after($after);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'round_off')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('round_off');
                });
            }
        }
    }
};
