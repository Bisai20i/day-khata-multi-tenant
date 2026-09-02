<?php

namespace App\Models;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

#[Fillable(['name', 'start_date', 'end_date', 'status'])]
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
        ];
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

    public static function current(): self
    {
        return static::where('status', FiscalYearStatus::Open)->firstOrFail();
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
