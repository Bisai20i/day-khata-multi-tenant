<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project master: a cost and revenue dimension that journal lines can be
 * tagged with (journal_voucher_lines.project_id, added by the next migration).
 * Money columns use the same decimal(20, 2) as the ledger.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('projects')) {
            return;
        }

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('name_np')->nullable();
            $table->string('client')->nullable();
            $table->decimal('contract_value', 20, 2)->default(0);
            $table->decimal('budget', 20, 2)->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status', 'projects_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
