<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One staff member's payslip inside a run. `gross` is the sum of its items,
 * `advance_recovery` is taken off against the staff member's outstanding
 * advance, and `net` (gross - recovery) is what the business owes them and is
 * credited to Salary Payable. What is still unpaid is derived, never stored:
 * net minus the posted payments allocated to this line.
 *
 * `branch_id` is the staff member's home branch when the payslip was saved;
 * PayrollDraft writes it explicitly. The payslip's expense, Salary Payable
 * and advance-recovery lines post in it, and a salary payment debits Salary
 * Payable in it, whichever branch pays (plan 12, section 5.3 party-line
 * rule). PayrollPoster refuses to post a payslip whose staff member has
 * since moved branch.
 */
#[Fillable(['payroll_run_id', 'staff_id', 'branch_id', 'gross', 'advance_recovery', 'net', 'note'])]
class PayrollLine extends Model
{
    use HasFactory;

    protected $table = 'hr_payroll_lines';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gross' => Decimal::class.':2',
            'advance_recovery' => Decimal::class.':2',
            'net' => Decimal::class.':2',
            'branch_id' => 'integer',
        ];
    }

    /**
     * Total of posted (not cancelled) payments allocated to each line, keyed
     * by line id. One grouped query for any number of lines.
     *
     * @param  iterable<int>  $lineIds
     * @return array<int, Money>
     */
    public static function paidAmounts(iterable $lineIds): array
    {
        $ids = collect($lineIds)->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $paid = array_fill_keys($ids, Money::zero());

        PayrollPaymentLine::query()
            ->whereIn('payroll_line_id', $ids)
            ->whereHas('payment', fn ($query) => $query->where('status', 'posted'))
            ->get(['payroll_line_id', 'amount'])
            ->each(function (PayrollPaymentLine $row) use (&$paid): void {
                $paid[$row->payroll_line_id] = $paid[$row->payroll_line_id]->plus($row->amount);
            });

        return $paid;
    }

    /**
     * Net still owed on each line, keyed by line id.
     *
     * @param  iterable<PayrollLine>  $lines
     * @return array<int, Money>
     */
    public static function outstandingAmounts(iterable $lines): array
    {
        $lines = collect($lines);
        $paid = static::paidAmounts($lines->pluck('id'));

        return $lines
            ->mapWithKeys(fn (PayrollLine $line): array => [$line->id => Money::of($line->net)->minus($paid[$line->id])])
            ->all();
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return BelongsTo<StaffMember, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'staff_id');
    }

    /**
     * @return HasMany<PayrollLineItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PayrollLineItem::class, 'payroll_line_id');
    }

    /**
     * @return HasMany<PayrollPaymentLine, $this>
     */
    public function paymentLines(): HasMany
    {
        return $this->hasMany(PayrollPaymentLine::class, 'payroll_line_id');
    }
}
