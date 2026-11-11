<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A salary payment: cash or bank going out to settle one or more payroll
 * lines. Always allocated (unlike a supplier payment there is no "on account"
 * remainder - paying ahead is a StaffAdvance). Posts
 * `Dr Salary Payable / Cr cash-or-bank` through App\Support\Hr\SalaryPaymentPoster.
 * `branch_id` is the paying branch, where the money moved (plan 12, section
 * 5.3): the cash or bank line posts there, while each Salary Payable debit
 * posts in its payslip's branch, with Inter-Branch Clearing lines when the
 * two differ.
 */
#[Fillable([
    'date', 'amount', 'payment_mode', 'bank_account_id', 'reference_number', 'narration', 'status',
    'payment_number', 'fiscal_year_id', 'journal_voucher_id', 'reversal_journal_voucher_id', 'branch_id',
    'created_by', 'cancelled_at', 'cancelled_by', 'cancel_reason',
])]
class PayrollPayment extends Model
{
    use HasFactory;

    protected $table = 'hr_payroll_payments';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'amount' => Decimal::class.':2',
            'cancelled_at' => 'datetime',
            'branch_id' => 'integer',
        ];
    }

    /**
     * @return HasMany<PayrollPaymentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollPaymentLine::class, 'payroll_payment_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function journalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class);
    }

    /**
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function reversalJournalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class, 'reversal_journal_voucher_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
