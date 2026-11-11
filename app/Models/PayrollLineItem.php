<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component amount on a payslip (Salary 50,000, Allowance 3,000, ...).
 */
#[Fillable(['payroll_line_id', 'pay_component_id', 'amount', 'note'])]
class PayrollLineItem extends Model
{
    use HasFactory;

    protected $table = 'hr_payroll_line_items';

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
     * @return BelongsTo<PayrollLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(PayrollLine::class, 'payroll_line_id');
    }

    /**
     * @return BelongsTo<PayComponent, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }
}
