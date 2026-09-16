<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved note text a cashier can drop straight into a sale's narration (audit
 * section 4 polish, "note templates"). Deliberately tiny - one text column,
 * no per-user scoping, no ordering column - since this is a shared, tenant-
 * wide list of a handful of boilerplate lines ("Thank you for your
 * business.", "Goods once sold are not returnable.") rather than a document
 * of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_note_templates', function (Blueprint $table) {
            $table->id();
            $table->string('text', 500);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_note_templates');
    }
};
