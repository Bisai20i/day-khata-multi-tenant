<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of a salary payment settles which payslip. Rows stay in place when
 * the payment is cancelled (history); PayrollLine::paidAmounts() ignores
 * allocations whose payment is cancelled, so the payslip reopens itself.
 *
 * `amount` is what the row settles against Salary Payable; `advance_adjusted`
 * is the part of it offset against the staff member's advance (credited to
 * Staff Advances Receivable) instead of paid in cash, so cash or bank paid is
 * `amount - advance_adjusted`.
 */
#[Fillable(['payroll_payment_id', 'payroll_line_id', 'amount', 'advance_adjusted'])]
class PayrollPaymentLine extends Model
{
    use HasFactory;

    protected $table = 'hr_payroll_payment_lines';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => Decimal::class.':2',
            'advance_adjusted' => Decimal::class.':2',
        ];
    }

    /**
     * @return BelongsTo<PayrollPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PayrollPayment::class, 'payroll_payment_id');
    }

    /**
     * @return BelongsTo<PayrollLine, $this>
     */
    public function payrollLine(): BelongsTo
    {
        return $this->belongsTo(PayrollLine::class, 'payroll_line_id');
    }
}
