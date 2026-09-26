<?php

namespace App\Models;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
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
    'closed_by', 'closed_at', 'close_reason',
    'reopened_by', 'reopened_at', 'reopen_reason', 'relocked_at',
])]
class FiscalYear extends Model
{
    /**
     * The three chart-of-accounts codes the periodic-inventory close runs
     * on (CONTRACTS C10: stock documents post no journal at all, so stock
     * only ever enters the ledger here and in the statements).
     *
     * - AS11 "Stock in Hand" is the balance-sheet asset that carries the
     *   closing value forward into the next year's opening balances.
     * - EXE9 "Opening Stock" is the trading-account debit: last year's
     *   closing stock is this year's cost of goods available for sale.
     * - INI22 "Closing Stock" is the trading-account credit that takes the
     *   unsold stock back out of cost of sales.
     *
     * Seeded by Database\Seeders\Tenant\ChartOfAccountsSeeder and backfilled
     * for existing tenants by the 2026_09_13_110000 migration.
     */
    public const STOCK_IN_HAND_CODE = 'AS11';

    public const OPENING_STOCK_CODE = 'EXE9';

    public const CLOSING_STOCK_CODE = 'INI22';

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

    /**
     * Shown whenever something tries to post while the company has no open
     * fiscal year (shared banner, and the error a posting request gets back).
     */
    public const NO_OPEN_YEAR_MESSAGE = 'No open fiscal year. Set up a fiscal year before posting sales, purchases or other entries.';

    public static function current(): self
    {
        return static::where('status', FiscalYearStatus::Open)->firstOrFail();
    }

    public static function hasOpen(): bool
    {
        return static::where('status', FiscalYearStatus::Open)->exists();
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
     *
     * relocked_at is cleared here, not merely set in relock(). A year that
     * has already been through one reopen/relock cycle still carries the old
     * relocked_at timestamp, and isOpenForCorrection() reads "reopened_at
     * set AND relocked_at null" - so without this line a second reopen
     * silently did nothing: the flag stayed false, every correction was
     * still refused, and relock() then rejected the year as "not currently
     * reopened for correction" (audit P1, reopen after relock).
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

        // A correction posted into this year is rolled forward by
        // JournalVoucher::rollForward() into every later year, and an
        // archived year's cold-storage copy was taken before that
        // roll-forward existed - writing into the live rows now would leave
        // the ledger and the archive permanently disagreeing, and the
        // archive is deliberately never rewritten. Refuse up front, naming
        // the archived year, rather than letting the correction post and
        // quietly diverge (T11 task 4, audit P0-19).
        $archivedLaterYear = static::query()
            ->whereKeyNot($this->getKey())
            ->where('start_date', '>', $this->start_date->toDateString())
            ->whereHas('archive')
            ->orderBy('start_date')
            ->first();

        if ($archivedLaterYear) {
            throw new InvalidArgumentException(
                "\"{$this->name}\" cannot be reopened: a correction posted into it has to roll forward into \"{$archivedLaterYear->name}\", which has already been archived. Record the correction in the current year instead."
            );
        }

        $alreadyOpenForCorrection = static::openForCorrection();
        if ($alreadyOpenForCorrection && $alreadyOpenForCorrection->isNot($this)) {
            throw new InvalidArgumentException("\"{$alreadyOpenForCorrection->name}\" is already reopened for correction; relock it first.");
        }

        $this->reopened_by = $actor->id;
        $this->reopened_at = now();
        $this->reopen_reason = $reason;
        $this->relocked_at = null;

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
     *
     * A correction posted into an already-closed year lands on profit-and-
     * loss accounts that close() had swept to zero, so the year is left
     * with unswept earnings and its Balance Sheet stops balancing (audit
     * P0-19). Relocking therefore posts a SUPPLEMENTARY closing entry that
     * sweeps whatever the corrections left behind into "Profit & Loss",
     * exactly the way the original sweep did. It is a no-op when the
     * corrections had no profit-and-loss effect at all: netBalance() then
     * reports zero for every account and postClosingEntries() writes
     * nothing.
     */
    public function relock(User $actor): void
    {
        if (! $this->isOpenForCorrection()) {
            throw new InvalidArgumentException('This fiscal year is not currently reopened for correction.');
        }

        DB::transaction(function () use ($actor) {
            $this->postClosingEntries($actor, "Supplementary year-end closing entries for {$this->name} (corrections posted while reopened)");

            $this->relocked_at = now();
            $this->save();
        });
    }

    /**
     * Closes this fiscal year and opens $next, in one transaction:
     *
     * 1. This year's depreciation for every active fixed asset (it has to
     *    reduce the year's profit before that profit is swept away).
     * 2. The periodic-inventory trading entries: last year's stock out of
     *    "Stock in Hand" into "Opening Stock", this year's closing stock
     *    (valued by StockCosting, CONTRACTS C10) back into "Stock in Hand"
     *    against "Closing Stock". Without the pair, the sweep below reports
     *    gross profit with no cost-of-goods-sold adjustment and the Balance
     *    Sheet carries no inventory at all (audit P0-17).
     * 3. The P&L sweep: every profit-and-loss account to zero, the net into
     *    "Profit & Loss".
     * 4. Every balance-sheet account's ending balance as $next's opening
     *    balances - which is what carries the closing stock value forward.
     *
     * Both fiscal year rows are re-read under lockForUpdate() and every
     * blocker is re-checked inside the transaction, so two simultaneous
     * close requests cannot both pass the status check and post two sets of
     * closing entries (audit P1, FY close race). SQLite treats
     * lockForUpdate() as a no-op, so that half is verified by review rather
     * than by a test.
     *
     * $closeReason is mandatory when the year has not actually finished yet,
     * and may then only be given by an admin: closing early freezes a period
     * that can still legitimately receive documents, so it has to be a
     * deliberate, attributable act rather than a mis-click (T11 task 7).
     */
    public function close(self $next, User $actor, ?string $closeReason = null): void
    {
        DB::transaction(function () use ($next, $actor, $closeReason) {
            // Ascending key order, the deadlock-avoidance convention this
            // app uses everywhere it locks more than one row (CONTRACTS C5).
            $keys = [$this->getKey(), $next->getKey()];
            sort($keys);
            static::query()->whereIn('id', $keys)->lockForUpdate()->get();

            $year = static::query()->whereKey($this->getKey())->firstOrFail();
            $nextYear = static::query()->whereKey($next->getKey())->firstOrFail();

            if ($year->status !== FiscalYearStatus::Open) {
                throw new InvalidArgumentException('Only the open fiscal year can be closed.');
            }

            if ($nextYear->is($year)) {
                throw new InvalidArgumentException('A fiscal year cannot be closed into itself.');
            }

            // The opening-balance voucher is dated $nextYear->start_date, so
            // a "next" year starting on or before this one ends would
            // restate balances inside a period this year still owns, and the
            // Cash Book would count the same money twice (audit P0-18).
            if ($nextYear->start_date->lessThanOrEqualTo($year->end_date)) {
                throw new InvalidArgumentException(
                    "\"{$nextYear->name}\" starts on {$nextYear->start_date->toDateString()}, on or before \"{$year->name}\" ends on {$year->end_date->toDateString()}. The next fiscal year has to start after this one ends."
                );
            }

            // Re-checked here rather than only in the controller: a second
            // close request that slipped past the controller check while the
            // first was still running would otherwise post a second set of
            // opening balances into $nextYear.
            if ($nextYear->journalVouchers()->exists()) {
                throw new InvalidArgumentException("\"{$nextYear->name}\" already has vouchers posted and cannot be used as the next year.");
            }

            $today = CarbonImmutable::now('Asia/Kathmandu')->startOfDay();

            if ($today->lessThanOrEqualTo(CarbonImmutable::parse($year->end_date->toDateString()))) {
                if (trim((string) $closeReason) === '') {
                    throw new InvalidArgumentException(
                        "\"{$year->name}\" does not end until {$year->end_date->toDateString()}. Closing it early needs an admin and a written reason."
                    );
                }

                if ($actor->role?->slug !== 'admin') {
                    throw new AuthorizationException('Only an admin may close a fiscal year before it has ended.');
                }
            }

            FixedAsset::postDepreciationForFiscalYear($year, $actor);
            $year->postStockTradingEntries($actor);
            $year->postClosingEntries($actor);
            $year->postOpeningBalances($nextYear, $actor);

            $year->status = FiscalYearStatus::Closed;
            $year->closed_by = $actor->id;
            $year->closed_at = now();
            $year->close_reason = trim((string) $closeReason) === '' ? null : $closeReason;
            $year->save();

            $nextYear->status = FiscalYearStatus::Open;
            $nextYear->save();

            // The caller holds $this and $next, not the re-read copies the
            // work was actually done on - keep them in step so a caller that
            // reads ->status straight after close() sees the truth.
            $this->forceFill($year->getAttributes())->syncOriginal();
            $next->forceFill($nextYear->getAttributes())->syncOriginal();
        });
    }

    /**
     * The periodic-inventory pair, both dated this year's last day and both
     * posted as ClosingEntry vouchers:
     *
     *   Dr Opening Stock  / Cr Stock in Hand   (whatever stock was brought in)
     *   Dr Stock in Hand  / Cr Closing Stock   (what is actually on hand now)
     *
     * After the pair, "Stock in Hand" holds exactly the closing value, which
     * postOpeningBalances() carries into the new year; "Opening Stock" and
     * "Closing Stock" are profit-and-loss accounts, so the sweep that runs
     * next folds the stock movement into gross profit.
     *
     * Each voucher touches "Stock in Hand", which is what
     * AccountingReportController uses to tell these two apart from the P&L
     * sweep (the sweep only ever touches profit-and-loss accounts plus
     * "Profit & Loss" itself) - see its isSweepVoucher() docblock.
     *
     * Both directions are handled rather than assuming a debit balance: a
     * tenant running on allow_negative_stock can hold a negative stock
     * position, and a negative line is rightly refused by
     * JournalVoucher::write().
     */
    private function postStockTradingEntries(User $actor): void
    {
        $stockInHand = Account::where('code', self::STOCK_IN_HAND_CODE)->first();
        $openingStockAccount = Account::where('code', self::OPENING_STOCK_CODE)->first();
        $closingStockAccount = Account::where('code', self::CLOSING_STOCK_CODE)->first();

        if (! $stockInHand || ! $openingStockAccount || ! $closingStockAccount) {
            throw new InvalidArgumentException(
                'The trading stock accounts are missing from the chart of accounts ('
                .self::STOCK_IN_HAND_CODE.' Stock in Hand, '
                .self::OPENING_STOCK_CODE.' Opening Stock, '
                .self::CLOSING_STOCK_CODE.' Closing Stock). Run the tenant migrations before closing a fiscal year.'
            );
        }

        $endDate = $this->end_date->toDateString();
        $openingStock = $this->netBalance($stockInHand);

        if (! $openingStock->isZero()) {
            $amount = $openingStock->abs()->toString();

            JournalVoucher::write(
                $this,
                VoucherType::ClosingEntry,
                $endDate,
                "Opening stock transferred to the trading account for {$this->name}",
                null,
                $actor,
                $openingStock->isPositive()
                    ? [
                        ['account_id' => $openingStockAccount->id, 'debit' => $amount, 'credit' => '0.00', 'narration' => 'Opening stock'],
                        ['account_id' => $stockInHand->id, 'debit' => '0.00', 'credit' => $amount, 'narration' => 'Opening stock'],
                    ]
                    : [
                        ['account_id' => $stockInHand->id, 'debit' => $amount, 'credit' => '0.00', 'narration' => 'Opening stock'],
                        ['account_id' => $openingStockAccount->id, 'debit' => '0.00', 'credit' => $amount, 'narration' => 'Opening stock'],
                    ],
            );
        }

        $closingStock = StockCosting::totalClosingValue($endDate);

        if ($closingStock->isZero()) {
            return;
        }

        $amount = $closingStock->abs()->toString();

        JournalVoucher::write(
            $this,
            VoucherType::ClosingEntry,
            $endDate,
            "Closing stock on hand at {$endDate}",
            null,
            $actor,
            $closingStock->isPositive()
                ? [
                    ['account_id' => $stockInHand->id, 'debit' => $amount, 'credit' => '0.00', 'narration' => 'Closing stock'],
                    ['account_id' => $closingStockAccount->id, 'debit' => '0.00', 'credit' => $amount, 'narration' => 'Closing stock'],
                ]
                : [
                    ['account_id' => $closingStockAccount->id, 'debit' => $amount, 'credit' => '0.00', 'narration' => 'Closing stock'],
                    ['account_id' => $stockInHand->id, 'debit' => '0.00', 'credit' => $amount, 'narration' => 'Closing stock'],
                ],
        );
    }

    /**
     * Sweeps every profit-and-loss account in this year to zero and posts
     * the net to "Profit & Loss".
     *
     * Every amount here is a Money string, never a float. netBalance() used
     * to hand `(float) SUM(debit) - SUM(credit)` straight to
     * JournalVoucher::write(), which now refuses any line carrying more than
     * two decimals - so an ordinary float summation artefact such as
     * 2261.1000000000004 threw InvalidAmount and took the whole year-end
     * close down with it. Test fixtures use round figures, which is exactly
     * why the suite stayed green while a real tenant's close would have
     * failed (audit P0-1, reported by T03).
     */
    private function postClosingEntries(User $actor, ?string $narration = null): void
    {
        $plAccount = Account::where('name', 'Profit & Loss')->firstOrFail();
        $accounts = $this->accountsWhereHeadIsProfitAndLoss(true);

        $lines = [];
        $totalZeroingDebit = Money::zero();
        $totalZeroingCredit = Money::zero();

        foreach ($accounts as $account) {
            $net = $this->netBalance($account);

            if ($net->isZero()) {
                continue;
            }

            $amount = $net->abs();

            if ($net->isPositive()) {
                $lines[] = ['account_id' => $account->id, 'debit' => '0.00', 'credit' => $amount->toString(), 'narration' => 'Year-end closing'];
                $totalZeroingCredit = $totalZeroingCredit->plus($amount);
            } else {
                $lines[] = ['account_id' => $account->id, 'debit' => $amount->toString(), 'credit' => '0.00', 'narration' => 'Year-end closing'];
                $totalZeroingDebit = $totalZeroingDebit->plus($amount);
            }
        }

        if (! $lines) {
            return;
        }

        $netProfit = $totalZeroingDebit->minus($totalZeroingCredit);

        if (! $netProfit->isZero()) {
            $amount = $netProfit->abs()->toString();

            $lines[] = $netProfit->isPositive()
                ? ['account_id' => $plAccount->id, 'debit' => '0.00', 'credit' => $amount, 'narration' => 'Net profit for the year']
                : ['account_id' => $plAccount->id, 'debit' => $amount, 'credit' => '0.00', 'narration' => 'Net loss for the year'];
        }

        JournalVoucher::write(
            $this,
            VoucherType::ClosingEntry,
            $this->end_date->toDateString(),
            $narration ?? "Year-end closing entries for {$this->name}",
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
            $net = $this->netBalance($account);

            if ($net->isZero()) {
                continue;
            }

            $amount = $net->abs()->toString();

            $lines[] = $net->isPositive()
                ? ['account_id' => $account->id, 'debit' => $amount, 'credit' => '0.00', 'narration' => 'Opening balance']
                : ['account_id' => $account->id, 'debit' => '0.00', 'credit' => $amount, 'narration' => 'Opening balance'];
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

    /**
     * One account's net (debit - credit) balance within this fiscal year,
     * exact.
     *
     * The sum runs on scaled integers rather than on the raw DECIMAL for the
     * same reason Item::currentStockByItem() and StockCosting do it: SQLite
     * gives a decimal column REAL affinity, so a plain SUM() there comes
     * back as a float and an ordinary total reads as 2261.1000000000004.
     * Multiplying by 100 and casting to an integer inside SQL makes the sum
     * exact on SQLite and on MySQL alike, and dividing back by 100 is
     * lossless at the two decimals a Money holds.
     */
    private function netBalance(Account $account): Money
    {
        $cast = DB::connection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        $netScaled = JournalVoucherLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalVoucher', fn ($query) => $query->where('fiscal_year_id', $this->id))
            ->selectRaw(
                "COALESCE(SUM(CAST(ROUND(debit * 100) AS {$cast})), 0) - COALESCE(SUM(CAST(ROUND(credit * 100) AS {$cast})), 0) as net_scaled"
            )
            ->value('net_scaled');

        return Money::of(BigDecimal::of((int) $netScaled)->dividedBy(100, 2, RoundingMode::Unnecessary));
    }
}
