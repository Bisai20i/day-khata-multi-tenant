<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-wise salary accounting, part two.
 *
 * Salary TDS: a per-staff default rate (`hr_staff.tds_rate`, a percentage),
 * the TDS withheld on each payslip (`hr_payroll_lines.tds`) and the run's total
 * (`hr_payroll_runs.total_tds`). A payslip's net is now gross - advance
 * recovery - TDS; the run voucher credits TDS Payable (LIA21) for the TDS.
 *
 * Advance adjustment at payment time: `hr_payroll_payment_lines.advance_adjusted`
 * is the part of what a payment settles against Salary Payable that is offset
 * against the staff member's advance instead of being paid in cash (the line's
 * `amount` keeps meaning "settled against Salary Payable", so every existing
 * payable figure is unchanged); `hr_payroll_payments.advance_adjusted` is the
 * payment's total of it. Cash or bank paid = amount - advance_adjusted.
 *
 * Every new column defaults to zero, so existing rows and balances are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addDecimal('hr_staff', 'tds_rate', 5, 'is_active');
        $this->addDecimal('hr_payroll_lines', 'tds', 20, 'advance_recovery');
        $this->addDecimal('hr_payroll_runs', 'total_tds', 20, 'total_recovery');
        $this->addDecimal('hr_payroll_payment_lines', 'advance_adjusted', 20, 'amount');
        $this->addDecimal('hr_payroll_payments', 'advance_adjusted', 20, 'amount');
    }

    public function down(): void
    {
        $this->dropColumn('hr_payroll_payments', 'advance_adjusted');
        $this->dropColumn('hr_payroll_payment_lines', 'advance_adjusted');
        $this->dropColumn('hr_payroll_runs', 'total_tds');
        $this->dropColumn('hr_payroll_lines', 'tds');
        $this->dropColumn('hr_staff', 'tds_rate');
    }

    private function addDecimal(string $table, string $column, int $precision, string $after): void
    {
        if (Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $precision, $after): void {
            $blueprint->decimal($column, $precision, 2)->default(0)->after($after);
        });
    }

    private function dropColumn(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
    }
};
