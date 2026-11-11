<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money given to a staff member ahead of salary. Posts
 * `Dr Staff Advances Receivable / Cr cash-or-bank` (the Balance Sheet
 * "Receivable") through App\Support\Hr\StaffAdvancePoster. It is recovered
 * only through a payroll line's `advance_recovery`; the outstanding advance is
 * always computed (App\Support\Hr\StaffBalances), never stored per advance.
 * `branch_id` is the paying branch, where the money moved (default: the
 * staff member's home branch). The Staff Advances Receivable debit posts in
 * the staff member's home branch and the cash or bank credit in the paying
 * branch, with Inter-Branch Clearing lines when the two differ (plan 12,
 * section 5.3).
 */
#[Fillable([
    'staff_id', 'date', 'amount', 'payment_mode', 'bank_account_id', 'reference_number', 'narration', 'status',
    'advance_number', 'fiscal_year_id', 'journal_voucher_id', 'reversal_journal_voucher_id', 'branch_id',
    'created_by', 'cancelled_at', 'cancelled_by', 'cancel_reason',
])]
class StaffAdvance extends Model
{
    use HasFactory;

    protected $table = 'hr_staff_advances';

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
     * @return BelongsTo<StaffMember, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'staff_id');
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
