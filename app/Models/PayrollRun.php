<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Support\Branches\SeriesNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payroll run: a period's pay for a set of staff, posted as a SINGLE
 * voucher (VoucherType::Payroll). A run is draft until posted; a posted run is
 * immutable and is cancelled by reversing its voucher, like every other
 * document. Posting and cancelling live in App\Support\Hr\PayrollPoster.
 *
 * `branch_id` is the run's origin branch (plan 12, BR09): the branch it was
 * generated for (the actor's posting branch when none is named) and the
 * header branch of its voucher. Its payslips may belong to several branches
 * (a company-wide run); each payslip carries its staff member's home branch
 * and PayrollPoster posts that payslip's expense, payable and recovery lines
 * there, so every branch of the voucher balances on its own.
 */
#[Fillable([
    'period_label', 'period_start', 'period_end', 'date', 'status', 'run_number', 'series_branch_id', 'fiscal_year_id',
    'journal_voucher_id', 'reversal_journal_voucher_id', 'total_gross', 'total_recovery', 'total_net',
    'narration', 'branch_id', 'created_by', 'posted_by', 'posted_at', 'cancelled_at', 'cancelled_by', 'cancel_reason',
])]
class PayrollRun extends Model
{
    use HasFactory;

    /**
     * Memoised series code of a branch-series run (displayNumber()).
     */
    private ?string $seriesCode = null;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'hr_payroll_runs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'date' => 'date:Y-m-d',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total_gross' => Decimal::class.':2',
            'total_recovery' => Decimal::class.':2',
            'total_net' => Decimal::class.':2',
            'branch_id' => 'integer',
            'series_branch_id' => 'integer',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    /**
     * "PAY-12" once posted (the voucher number), null while a draft. A run
     * numbered in a branch series of a per_branch year (plan 12, BR20)
     * prints "PAY-{series_code}-12", like its voucher. Callers that select
     * columns must include series_branch_id; a shared run costs no query, a
     * branch-series run one query per run instance.
     */
    public function displayNumber(): ?string
    {
        if ($this->run_number === null) {
            return null;
        }

        $seriesBranchId = (int) $this->getAttribute('series_branch_id');

        if ($seriesBranchId === 0) {
            return SeriesNumber::format((int) $this->run_number, null, 'PAY');
        }

        $this->seriesCode ??= trim((string) Branch::query()->whereKey($seriesBranchId)->value('series_code'));

        return SeriesNumber::format((int) $this->run_number, $this->seriesCode !== '' ? $this->seriesCode : (string) $seriesBranchId, 'PAY');
    }

    /**
     * Resolves the series codes of every branch-series run in the list with
     * one query (none when all are shared), so displayNumber() can be called
     * per row without an N+1. Runs must carry series_branch_id.
     *
     * @param  iterable<self>  $runs
     */
    public static function loadSeriesCodes(iterable $runs): void
    {
        $pending = [];

        foreach ($runs as $run) {
            if ($run instanceof self && (int) $run->getAttribute('series_branch_id') !== 0 && $run->seriesCode === null) {
                $pending[] = $run;
            }
        }

        if ($pending === []) {
            return;
        }

        $codes = Branch::query()
            ->whereIn('id', array_values(array_unique(array_map(fn (self $run): int => (int) $run->getAttribute('series_branch_id'), $pending))))
            ->pluck('series_code', 'id');

        foreach ($pending as $run) {
            $run->seriesCode = trim((string) $codes->get((int) $run->getAttribute('series_branch_id')));
        }
    }

    /**
     * @return HasMany<PayrollLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class, 'payroll_run_id');
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
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
