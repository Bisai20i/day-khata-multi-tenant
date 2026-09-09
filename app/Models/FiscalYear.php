<?php

namespace App\Models;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Support\NepaliCalendar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

#[Fillable([
    'name', 'start_date', 'end_date', 'status',
    'closed_by', 'closed_at', 'reopened_by', 'reopened_at', 'reopen_reason', 'relocked_at',
])]
class FiscalYear extends Model
{
    protected static function booted(): void
    {
        static::saving(function (self $fiscalYear): void {
            if ($fiscalYear->start_date->greaterThanOrEqualTo($fiscalYear->end_date)) {
                throw new InvalidArgumentException("A fiscal year's start date must be before its end date.");
            }

            $overlaps = static::query()
                ->when($fiscalYear->exists, fn ($query) => $query->whereKeyNot($fiscalYear->getKey()))
                ->where('start_date', '<=', $fiscalYear->end_date->toDateString())
                ->where('end_date', '>=', $fiscalYear->start_date->toDateString())
                ->exists();

            if ($overlaps) {
                throw new InvalidArgumentException("This fiscal year's date range overlaps an existing fiscal year.");
            }

            if ($fiscalYear->status === FiscalYearStatus::Open) {
                $alreadyOpen = static::query()
                    ->when($fiscalYear->exists, fn ($query) => $query->whereKeyNot($fiscalYear->getKey()))
                    ->where('status', FiscalYearStatus::Open)
                    ->exists();

                if ($alreadyOpen) {
                    throw new InvalidArgumentException('Only one fiscal year may be open at a time.');
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => FiscalYearStatus::class,
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'relocked_at' => 'datetime',
        ];
    }

    /**
     * BS (Bikram Sambat) fiscal-year label, e.g. "2081/82", derived from
     * start_date at read time via NepaliCalendar. Deliberately not a stored
     * column - it's fully derivable from start_date, and this app avoids
     * denormalized state that can drift out of sync with the column it's
     * derived from (no DB triggers, no cached-and-copied values).
     */
    protected function bsLabel(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $bs = NepaliCalendar::adToBs($this->start_date);

                return sprintf('%d/%02d', $bs['year'], ($bs['year'] + 1) % 100);
            },
        );
    }

    /**
     * @return HasMany<JournalVoucher, $this>
     */
    public function journalVouchers(): HasMany
    {
        return $this->hasMany(JournalVoucher::class);
    }

    /**
     * @return HasMany<VoucherSequence, $this>
     */
    public function voucherSequences(): HasMany
    {
        return $this->hasMany(VoucherSequence::class);
    }

    /**
     * Present once this fiscal year has been copied out to cold storage -
     * see App\Support\FiscalYear\FiscalYearArchiver.
     *
     * @return HasOne<FiscalYearArchive, $this>
     */
    public function archive(): HasOne
    {
        return $this->hasOne(FiscalYearArchive::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public static function current(): self
    {
        return static::where('status', FiscalYearStatus::Open)->firstOrFail();
    }

    /**
     * The single closed year currently reopened for correction, if any -
     * shared lookup used by PurchaseController/JournalVoucherController/
     * StockAdjustmentController's create-side actions to offer it as the
     * only non-current fiscal-year option on their forms (see
     * isOpenForCorrection()'s docblock). There can only ever be one, since
     * relock() must run before a different year can be reopened.
     */
    public static function openForCorrection(): ?self
    {
        return static::query()->whereNotNull('reopened_at')->whereNull('relocked_at')->first();
    }

    /**
     * True while this closed year has been deliberately reopened for a
     * Purchase/Journal Voucher/Stock Adjustment correction and not yet
     * relocked - a flag on the closed year, not a distinct FiscalYearStatus
     * case (see the migration's docblock), so this checks reopened_at/
     * relocked_at directly rather than `status`.
     */
    public function isOpenForCorrection(): bool
    {
        return $this->reopened_at !== null && $this->relocked_at === null;
    }

    /**
     * Reopens this closed year for correction: an admin-only, mandatory-
     * reason action (enforced by the caller/route, see FiscalYearController
     * ::reopen()'s role:admin middleware) that flips on the window
     * ClosedFiscalYearGuard checks. Locked design decision - see
     * plans/invoicing-settings-sale-purchase-ux.md "Locked decisions" #3 and
     * Phase D's recommended option (a): the Purchase/Journal Voucher/Stock
     * Adjustment create forms gain a fiscal-year picker offering only this
     * reopened year as an alternate to the current one, rather than a
     * separate "post correction" flow.
     */
    public function reopen(User $actor, string $reason): void
    {
        if ($this->status !== FiscalYearStatus::Closed) {
            throw new InvalidArgumentException('Only a closed fiscal year can be reopened.');
        }

        if ($this->archive()->exists()) {
            throw new InvalidArgumentException('An archived fiscal year cannot be reopened.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to reopen a fiscal year.');
        }

        $alreadyOpenForCorrection = static::openForCorrection();
        if ($alreadyOpenForCorrection && $alreadyOpenForCorrection->isNot($this)) {
            throw new InvalidArgumentException("\"{$alreadyOpenForCorrection->name}\" is already reopened for correction; relock it first.");
        }

        $this->reopened_by = $actor->id;
        $this->reopened_at = now();
        $this->reopen_reason = $reason;

        // Best-effort backfill for a year that was closed before closed_by/
        // closed_at existed (see the migration's docblock) - never
        // overwrites a real value already on record.
        if ($this->closed_by === null) {
            $this->closed_by = $actor->id;
        }
        if ($this->closed_at === null) {
            $this->closed_at = now();
        }

        $this->save();
    }

    /**
     * Ends this year's reopened-for-correction window, re-blocking
     * Purchase/Journal Voucher/Stock Adjustment postings against it.
     */
    public function relock(): void
    {
        if (! $this->isOpenForCorrection()) {
            throw new InvalidArgumentException('This fiscal year is not currently reopened for correction.');
        }

        $this->relocked_at = now();
        $this->save();
    }

    /**
     * Closes this fiscal year and opens $next: posts this year's
     * depreciation for every active fixed asset (must happen before the
     * P&L sweep below, since depreciation reduces this year's profit),
     * sweeps every profit-and-loss account to zero (posting the net to
     * "Profit & Loss"), carries every balance-sheet account's ending
     * balance forward as $next's opening balances, then flips the status
     * of both years. All in one transaction.
     */
    public function close(self $next, User $actor): void
    {
        if ($this->status !== FiscalYearStatus::Open) {
            throw new InvalidArgumentException('Only the open fiscal year can be closed.');
        }

        DB::transaction(function () use ($next, $actor) {
            FixedAsset::postDepreciationForFiscalYear($this, $actor);
            $this->postClosingEntries($actor);
            $this->postOpeningBalances($next, $actor);

            $this->status = FiscalYearStatus::Closed;
            $this->closed_by = $actor->id;
            $this->closed_at = now();
            $this->save();

            $next->status = FiscalYearStatus::Open;
            $next->save();
        });
    }

    private function postClosingEntries(User $actor): void
    {
        $plAccount = Account::where('name', 'Profit & Loss')->firstOrFail();
        $accounts = $this->accountsWhereHeadIsProfitAndLoss(true);

        $lines = [];
        $totalZeroingDebit = 0.0;
        $totalZeroingCredit = 0.0;

        foreach ($accounts as $account) {
            $net = (float) $this->netBalance($account);

            if (round($net, 2) === 0.0) {
                continue;
            }

            if ($net > 0) {
                $lines[] = ['account_id' => $account->id, 'debit' => 0, 'credit' => $net, 'narration' => 'Year-end closing'];
                $totalZeroingCredit += $net;
            } else {
                $lines[] = ['account_id' => $account->id, 'debit' => -$net, 'credit' => 0, 'narration' => 'Year-end closing'];
                $totalZeroingDebit += -$net;
            }
        }

        if (! $lines) {
            return;
        }

        $netProfit = $totalZeroingDebit - $totalZeroingCredit;

        if (round($netProfit, 2) !== 0.0) {
            $lines[] = $netProfit > 0
                ? ['account_id' => $plAccount->id, 'debit' => 0, 'credit' => $netProfit, 'narration' => 'Net profit for the year']
                : ['account_id' => $plAccount->id, 'debit' => -$netProfit, 'credit' => 0, 'narration' => 'Net loss for the year'];
        }

        JournalVoucher::write(
            $this,
            VoucherType::ClosingEntry,
            $this->end_date->toDateString(),
            "Year-end closing entries for {$this->name}",
            null,
            $actor,
            $lines,
        );
    }

    private function postOpeningBalances(self $next, User $actor): void
    {
        $accounts = $this->accountsWhereHeadIsProfitAndLoss(false);

        $lines = [];

        foreach ($accounts as $account) {
            $net = (float) $this->netBalance($account);

            if (round($net, 2) === 0.0) {
                continue;
            }

            $lines[] = $net > 0
                ? ['account_id' => $account->id, 'debit' => $net, 'credit' => 0, 'narration' => 'Opening balance']
                : ['account_id' => $account->id, 'debit' => 0, 'credit' => -$net, 'narration' => 'Opening balance'];
        }

        // Balance-sheet accounts' net balances sum to exactly zero once the
        // closing entries above have zeroed every profit-and-loss account
        // (the fiscal year's total debit/credit always balances, and the
        // P&L subtotal is now zero, so the balance-sheet subtotal must be
        // too) - so this list can never contain exactly one nonzero line;
        // it's either empty or self-balancing on its own.
        if (count($lines) < 2) {
            return;
        }

        JournalVoucher::write(
            $next,
            VoucherType::OpeningBalance,
            $next->start_date->toDateString(),
            "Opening balances carried forward from {$this->name}",
            null,
            $actor,
            $lines,
        );
    }

    /**
     * @return Collection<int, Account>
     */
    private function accountsWhereHeadIsProfitAndLoss(bool $isProfitAndLoss)
    {
        return Account::query()
            ->whereHas('group.accountHead', fn ($query) => $query->where('is_profit_and_loss', $isProfitAndLoss))
            ->orWhereHas('subgroup.accountGroup.accountHead', fn ($query) => $query->where('is_profit_and_loss', $isProfitAndLoss))
            ->get();
    }

    private function netBalance(Account $account): string|float
    {
        return JournalVoucherLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalVoucher', fn ($query) => $query->where('fiscal_year_id', $this->id))
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');
    }
}
