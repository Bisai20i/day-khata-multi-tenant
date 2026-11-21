<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee and employer SSF and CIT contributions in payroll, mirroring the
 * salary TDS columns (2026_11_18_100000).
 *
 * - `hr_staff`: default percentages `ssf_employee_rate`, `ssf_employer_rate`,
 *   `cit_employee_rate`, `cit_employer_rate` (0-100, 2 decimals) the payroll
 *   draft pre-fills each payslip with.
 * - `hr_pay_components.is_contribution_base`: the component counts as the basic
 *   pay the SSF / CIT percentages apply to. Backfilled true for the starter
 *   SALARY component only.
 * - `hr_payroll_lines`: `ssf_employee`, `ssf_employer`, `cit_employee`,
 *   `cit_employer`. A payslip's net is gross - advance recovery - TDS -
 *   employee SSF - employee CIT. The employer amounts are an extra cost to the
 *   employer and change neither gross nor net.
 * - `hr_payroll_runs`: the four matching totals.
 *
 * Every new column defaults to zero / false, so existing rows, payslips and
 * balances are untouched. The SALARY backfill uses a literal code, never config.
 */
return new class extends Migration
{
    private const STAFF_RATES = [
        'ssf_employee_rate' => 'tds_rate',
        'ssf_employer_rate' => 'ssf_employee_rate',
        'cit_employee_rate' => 'ssf_employer_rate',
        'cit_employer_rate' => 'cit_employee_rate',
    ];

    private const LINE_AMOUNTS = [
        'ssf_employee' => 'tds',
        'ssf_employer' => 'ssf_employee',
        'cit_employee' => 'ssf_employer',
        'cit_employer' => 'cit_employee',
    ];

    private const RUN_TOTALS = [
        'total_ssf_employee' => 'total_tds',
        'total_ssf_employer' => 'total_ssf_employee',
        'total_cit_employee' => 'total_ssf_employer',
        'total_cit_employer' => 'total_cit_employee',
    ];

    public function up(): void
    {
        foreach (self::STAFF_RATES as $column => $after) {
            $this->addDecimal('hr_staff', $column, 5, $after);
        }

        if (Schema::hasTable('hr_pay_components') && ! Schema::hasColumn('hr_pay_components', 'is_contribution_base')) {
            Schema::table('hr_pay_components', function (Blueprint $blueprint): void {
                $blueprint->boolean('is_contribution_base')->default(false)->after('is_active');
            });
        }

        if (Schema::hasTable('hr_pay_components') && Schema::hasColumn('hr_pay_components', 'is_contribution_base')) {
            DB::table('hr_pay_components')->where('code', 'SALARY')->update(['is_contribution_base' => true]);
        }

        foreach (self::LINE_AMOUNTS as $column => $after) {
            $this->addDecimal('hr_payroll_lines', $column, 20, $after);
        }

        foreach (self::RUN_TOTALS as $column => $after) {
            $this->addDecimal('hr_payroll_runs', $column, 20, $after);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::RUN_TOTALS)) as $column) {
            $this->dropColumn('hr_payroll_runs', $column);
        }

        foreach (array_reverse(array_keys(self::LINE_AMOUNTS)) as $column) {
            $this->dropColumn('hr_payroll_lines', $column);
        }

        $this->dropColumn('hr_pay_components', 'is_contribution_base');

        foreach (array_reverse(array_keys(self::STAFF_RATES)) as $column) {
            $this->dropColumn('hr_staff', $column);
        }
    }

    private function addDecimal(string $table, string $column, int $precision, string $after): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $precision, $after): void {
            $blueprint->decimal($column, $precision, 2)->default(0)->after($after);
        });
    }

    private function dropColumn(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
    }
};
