<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manufacturing schema (MC5-MC7, MC9). Schema only: posting logic lives in
 * the models added by later tasks.
 *
 * Every table is created inside a hasTable guard so a re-run is a no-op.
 * Tables are dropped (not columns), which removes their foreign keys and
 * unique indexes with them on both SQLite and MySQL, in reverse dependency
 * order. Index names are explicit and short (MySQL's 64-character limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('production_entries')) {
            Schema::create('production_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('number');
                $table->date('date');
                $table->foreignId('store_id')->constrained()->restrictOnDelete();
                $table->foreignId('finished_item_id')->constrained('items')->restrictOnDelete();
                $table->decimal('planned_quantity', 15, 4);
                $table->string('status', 20)->default('in_progress');
                $table->string('narration')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancel_reason')->nullable();
                $table->timestamps();

                $table->unique(['fiscal_year_id', 'number'], 'prod_entries_fy_number_unique');
                $table->index('status', 'prod_entries_status_index');
            });
        }

        if (! Schema::hasTable('material_issue_notes')) {
            Schema::create('material_issue_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('number');
                $table->date('date');
                $table->foreignId('store_id')->constrained()->restrictOnDelete();
                $table->foreignId('production_entry_id')->nullable()->constrained()->restrictOnDelete();
                $table->decimal('total_cost', 15, 2)->default(0);
                $table->string('narration')->nullable();
                $table->string('status', 20)->default('posted');
                $table->foreignId('journal_voucher_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('reversal_journal_voucher_id')->nullable()->constrained('journal_vouchers')->restrictOnDelete();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancel_reason')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();

                $table->unique(['fiscal_year_id', 'number'], 'mat_issue_notes_fy_number_unique');
            });
        }

        if (! Schema::hasTable('material_issue_items')) {
            Schema::create('material_issue_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('material_issue_note_id')->constrained()->cascadeOnDelete();
                $table->foreignId('item_id')->constrained()->restrictOnDelete();
                $table->foreignId('item_unit_id')->nullable()->constrained()->restrictOnDelete();
                $table->decimal('quantity', 15, 4);
                $table->decimal('base_quantity', 15, 4);
                $table->decimal('unit_cost_rate', 15, 4)->default(0);
                $table->decimal('line_cost', 15, 2)->default(0);
                $table->foreignId('stock_movement_id')->nullable()->constrained('item_stock_movements')->restrictOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('production_direct_costs')) {
            Schema::create('production_direct_costs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('production_entry_id')->constrained()->restrictOnDelete();
                $table->date('date');
                $table->string('cost_type', 20);
                $table->string('description')->nullable();
                $table->decimal('amount', 15, 2);
                $table->foreignId('credit_account_id')->constrained('accounts')->restrictOnDelete();
                $table->foreignId('journal_voucher_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancel_reason')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('production_completions')) {
            Schema::create('production_completions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('production_entry_id')->constrained()->restrictOnDelete();
                $table->date('date');
                $table->decimal('quantity', 15, 4);
                $table->decimal('cost', 15, 2);
                $table->decimal('unit_cost', 15, 4)->default(0);
                $table->boolean('is_final')->default(false);
                $table->foreignId('stock_movement_id')->nullable()->constrained('item_stock_movements')->restrictOnDelete();
                $table->foreignId('journal_voucher_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancel_reason')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('production_wip_entries')) {
            Schema::create('production_wip_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('material_issue_note_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('production_entry_id')->nullable()->constrained()->restrictOnDelete();
                $table->date('date');
                $table->string('kind', 20);
                $table->foreignId('item_id')->nullable()->constrained()->restrictOnDelete();
                $table->decimal('quantity', 15, 4)->nullable();
                $table->decimal('value', 15, 2);
                $table->smallInteger('direction');
                $table->string('source_type')->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->boolean('cancelled')->default(false);
                $table->timestamps();

                $table->index(['source_type', 'source_id'], 'wip_entries_source_index');
                $table->index(['production_entry_id', 'cancelled'], 'wip_entries_entry_cancelled_index');
            });
        }

        if (! Schema::hasTable('fiscal_year_inventory_snapshots')) {
            Schema::create('fiscal_year_inventory_snapshots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
                $table->string('inventory_class', 20);
                $table->foreignId('item_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('production_entry_id')->nullable()->constrained()->restrictOnDelete();
                $table->decimal('quantity', 15, 4)->default(0);
                $table->decimal('value', 15, 2)->default(0);
                $table->timestamp('created_at')->nullable();

                $table->index(['fiscal_year_id', 'inventory_class'], 'fy_inv_snapshots_fy_class_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_year_inventory_snapshots');
        Schema::dropIfExists('production_wip_entries');
        Schema::dropIfExists('production_completions');
        Schema::dropIfExists('production_direct_costs');
        Schema::dropIfExists('material_issue_items');
        Schema::dropIfExists('material_issue_notes');
        Schema::dropIfExists('production_entries');
    }
};
