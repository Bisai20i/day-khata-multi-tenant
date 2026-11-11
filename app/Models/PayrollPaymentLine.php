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
 */
#[Fillable(['payroll_payment_id', 'payroll_line_id', 'amount'])]
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
