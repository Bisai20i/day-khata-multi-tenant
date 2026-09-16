<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A free-text per-line note (e.g. batch/lot number, a supplier's remark) -
 * purely cosmetic, printed on the bill, never read by any calculation
 * (audit section 4 polish, "note templates and per-line notes").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->string('note', 255)->nullable()->after('vatable');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
