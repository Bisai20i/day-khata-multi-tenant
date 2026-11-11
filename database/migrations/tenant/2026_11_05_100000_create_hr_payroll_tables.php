<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR module, part one: staff master, pay components, payroll runs, salary
 * payments and staff advances (plans/11-hr-staff-payroll.md).
 *
 * Every table is hr_-prefixed so later HR features (attendance, leave,
 * departments, loans) sit beside these without renames.
 *
 * `branch_id` is a plain nullable, indexed unsigned bigint with NO foreign key
 * on the staff, run, payment and advance tables: the per-tenant Branch feature
 * does not exist yet, and its own migration adds `branches` plus the foreign
 * keys. Declaring the exact column type now means that migration adds a
 * constraint, not a column, which avoids the MySQL FK type-mismatch trap.
 *
 * Every FK is restrictOnDelete: staff and documents are deactivated or
 * cancelled, never deleted. Money columns are DECIMAL(20,2), like the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional: if table five fails, tables one to
        // four stay behind. Skipping a table that already exists keeps the
        // migration re-runnable after such a partial failure.
        $create = function (string $name, Closure $definition): void {
            if (! Schema::hasTable($name)) {
                Schema::create($name, $definition);
            }
        };

        $create('hr_staff', function (Blueprint $table) {
            $table->id();
            $table->string('staff_code')->unique();
            $table->string('name');
            $table->string('mobile_no')->nullable();
            $table->string('address')->nullable();
            $table->string('designation')->nullable();
            // A plain string until an hr_departments table is worth having.
            $table->string('department')->nullable();
            $table->date('join_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('pan_no')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_no')->nullable();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->timestamps();
        });

        $create('hr_pay_components', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('kind')->default('earning');
            $table->foreignId('expense_account_id')->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $create('hr_staff_pay_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('hr_staff')->restrictOnDelete();
            $table->foreignId('pay_component_id')->constrained('hr_pay_components')->restrictOnDelete();
            $table->decimal('amount', 20, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'effective_from'], 'hr_pay_items_staff_from_idx');
        });

        $create('hr_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->string('period_label');
            $table->date('period_start');
            $table->date('period_end');
            // The voucher date; the fiscal year is resolved from it when posted.
            $table->date('date');
            $table->string('status')->default('draft');
            $table->unsignedInteger('run_number')->nullable();
            $table->foreignId('fiscal_year_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('journal_voucher_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_journal_voucher_id')->nullable()->constrained('journal_vouchers')->restrictOnDelete();
            $table->decimal('total_gross', 20, 2)->default(0);
            $table->decimal('total_recovery', 20, 2)->default(0);
            $table->decimal('total_net', 20, 2)->default(0);
            $table->string('narration')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'period_start'], 'hr_runs_status_period_idx');
            // run_number is the PAY voucher number, which restarts each fiscal year.
            $table->unique(['fiscal_year_id', 'run_number'], 'hr_runs_year_number_unique');
        });

        $create('hr_payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('hr_staff')->restrictOnDelete();
            $table->decimal('gross', 20, 2)->default(0);
            $table->decimal('advance_recovery', 20, 2)->default(0);
            $table->decimal('net', 20, 2)->default(0);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'staff_id'], 'hr_lines_run_staff_unique');
        });

        $create('hr_payroll_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_line_id')->constrained('hr_payroll_lines')->restrictOnDelete();
            $table->foreignId('pay_component_id')->constrained('hr_pay_components')->restrictOnDelete();
            $table->decimal('amount', 20, 2);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        $create('hr_payroll_payments', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->decimal('amount', 20, 2);
            $table->string('payment_mode');
            $table->foreignId('bank_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('reference_number')->nullable();
            $table->string('narration')->nullable();
            $table->string('status')->default('posted');
            $table->string('payment_number')->nullable();
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('reversal_journal_voucher_id')->nullable()->constrained('journal_vouchers')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
        });

        $create('hr_payroll_payment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_payment_id')->constrained('hr_payroll_payments')->restrictOnDelete();
            $table->foreignId('payroll_line_id')->constrained('hr_payroll_lines')->restrictOnDelete();
            $table->decimal('amount', 20, 2);
            $table->timestamps();

            $table->unique(['payroll_payment_id', 'payroll_line_id'], 'hr_paylines_payment_line_unique');
        });

        $create('hr_staff_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('hr_staff')->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 20, 2);
            $table->string('payment_mode');
            $table->foreignId('bank_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('reference_number')->nullable();
            $table->string('narration')->nullable();
            $table->string('status')->default('posted');
            $table->string('advance_number')->nullable();
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('reversal_journal_voucher_id')->nullable()->constrained('journal_vouchers')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_staff_advances');
        Schema::dropIfExists('hr_payroll_payment_lines');
        Schema::dropIfExists('hr_payroll_payments');
        Schema::dropIfExists('hr_payroll_line_items');
        Schema::dropIfExists('hr_payroll_lines');
        Schema::dropIfExists('hr_payroll_runs');
        Schema::dropIfExists('hr_staff_pay_items');
        Schema::dropIfExists('hr_pay_components');
        Schema::dropIfExists('hr_staff');
    }
};
