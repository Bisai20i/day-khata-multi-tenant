<?php

namespace App\Models;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchBalancer;
use App\Support\Branches\BranchContext;
use App\Support\Branches\InterBranchBalances;
use App\Support\Inventory\StockCosting;
use App\Support\Manufacturing\InventoryReconciliation;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
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

    /**
     * fiscal_years.numbering_scope values (plan 12, BR20). Shared is the
     * default and every year created before BR20.
     */
    public const NUMBERING_SHARED = 'shared';

    public const NUMBERING_PER_BRANCH = 'per_branch';

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
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
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
     * Verified closing inventory written by close()/relock() (MC9).
     *
     * @return HasMany<FiscalYearInventorySnapshot, $this>
     */
    public function inventorySnapshots(): HasMany
    {
        return $this->hasMany(FiscalYearInventorySnapshot::class);
    }

    /**
     * @return HasMany<VoucherSequence, $this>
     */
    public function voucherSequences(): HasMany
    {
        return $this->hasMany(VoucherSequence::class);
    }

    /**
     * How this year's voucher series are keyed (plan 12, section 5.8):
     * NUMBERING_SHARED (every year by default, one company-wide counter per
     * voucher type) or NUMBERING_PER_BRANCH. Read from the loaded row; a
     * model built without the column (a freshly created instance, or a
     * partial select) reads it once from the database and keeps it, so a
     * per-branch year is never mistaken for a shared one. Changed only by
     * App\Support\Branches\NumberingScope, which is why the column is not
     * fillable.
     */
    public function numberingScope(): string
    {
        if (! array_key_exists('numbering_scope', $this->getAttributes())) {
            $stored = $this->exists
                ? static::query()->whereKey($this->getKey())->value('numbering_scope')
                : null;

            $this->setAttribute('numbering_scope', $stored ?? self::NUMBERING_SHARED);
            $this->syncOriginalAttribute('numbering_scope');
        }

        return $this->getAttribute('numbering_scope') === self::NUMBERING_PER_BRANCH
            ? self::NUMBERING_PER_BRANCH
            : self::NUMBERING_SHARED;
    }

    public function usesPerBranchNumbering(): bool
    {
        return $this->numberingScope() === self::NUMBERING_PER_BRANCH;
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
     * Reopens this closed year for correction: an owner-only, mandatory-
     * reason action (enforced by the caller/route, see the reopen route's
     * can:fiscal_year.close_archive middleware) that flips on the window
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
     *
     * Reopening never reverses or replaces the opening-balance voucher: a
     * correction posted into the reopened year reaches the later years
     * through JournalVoucher::rollForward(), which keeps every line's branch,
     * and relock() sweeps per branch. Runs outside the branch access scope
     * (plan 12, section 5.7), like close() and relock(), so a year-level
     * decision always sees every branch.
     */
    public function reopen(User $actor, string $reason): void
    {
        BranchContext::unrestricted(function () use ($actor, $reason): void {
            $this->markReopenedForCorrection($actor, $reason);
        });
    }

    private function markReopenedForCorrection(User $actor, string $reason): void
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
     *
     * Relocking is also the "re-close" for inventory: the perpetual classes
     * are reconciled again (refusing the relock on a difference, so the year
     * stays open for the fix) and fiscal_year_inventory_snapshots is
     * rewritten from the corrected figures.
     *
     * The supplementary sweep is per branch, like close()'s, and the whole
     * relock runs outside the branch access scope so a restricted actor's
     * view can never drop another branch's corrections from it.
     */
    public function relock(User $actor): void
    {
        if (! $this->isOpenForCorrection()) {
            throw new InvalidArgumentException('This fiscal year is not currently reopened for correction.');
        }

        BranchContext::unrestricted(fn () => DB::transaction(function () use ($actor) {
            // A correction (purchase, stock adjustment) may have moved
            // perpetual stock, so the year is reconciled again and its
            // snapshots rewritten from the corrected figures. Until this
            // runs, reopen() leaves the previous snapshots in place.
            $endDate = $this->end_date->toDateString();
            $reconciled = InventoryReconciliation::assertReconciled($this, $endDate, 'relocked');

            // I8 per branch. A single-branch company has nothing more to
            // check: the company-wide pass above already covers Main.
            if (Branch::query()->count() > 1) {
                InventoryReconciliation::assertBranchesReconciled($this, $endDate, 'relocked');
            }

            // The same branch-books check close() runs before its sweep
            // (invariant I3): a correction posted while reopened must not
            // leave the clearing account, or one side of a branch stock
            // transfer, out of balance across branches. Single-branch
            // returns at once, as on close.
            $branchIds = $this->branchIdsForYearEnd();
            $this->assertBranchBooksCanClose($branchIds, 'relocked');

            $this->postClosingEntries($actor, $branchIds, "Supplementary year-end closing entries for {$this->name} (corrections posted while reopened)");

            FiscalYearInventorySnapshot::rewriteFor($this, $endDate, $reconciled);

            $this->relocked_at = now();
            $this->save();
        }));
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
     *    That includes the perpetual inventory accounts (AS12 Raw Material,
     *    AS13 WIP, AS14 Finished Goods): they are ordinary balance-sheet
     *    accounts whose balance already IS the stock value, so they are
     *    carried forward here like any other asset and are deliberately not
     *    touched by the trading pair in step 2 (MANUFACTURING MC9).
     *
     * Before any of that, InventoryReconciliation proves each perpetual
     * class's GL balance equals its subledger (cost_value balances for RM/FG,
     * production_wip_entries for WIP) and refuses the close, naming the class
     * and the difference, when one does not. After the postings, the verified
     * per-item / per-production-entry closing figures are written to
     * fiscal_year_inventory_snapshots, which next year's reports read as the
     * opening RM/WIP/FG. Reads inside one transaction share one consistent
     * snapshot on MySQL (REPEATABLE READ), so the reconciliation and the
     * snapshot rows see the same data.
     *
     * Both fiscal year rows are re-read under lockForUpdate() and every
     * blocker is re-checked inside the transaction, so two simultaneous
     * close requests cannot both pass the status check and post two sets of
     * closing entries (audit P1, FY close race). SQLite treats
     * lockForUpdate() as a no-op, so that half is verified by review rather
     * than by a test.
     *
     * $closeReason is mandatory when the year has not actually finished yet,
     * and may then only be given by the owner (fiscal_year.close_archive):
     * closing early freezes a period that can still legitimately receive
     * documents, so it has to be a deliberate, attributable act rather than
     * a mis-click (T11 task 7).
     *
     * Branches (plan 12, section 5.6): steps 2 to 4 run per branch inside
     * the same vouchers, so every branch's entries balance on their own
     * (invariant I1) and each branch's result lands in its own "Profit &
     * Loss" line. The branches are Main plus every other branch with a line
     * in this year or a store (branchIdsForYearEnd()); a company that only
     * ever used Main gets exactly the vouchers, lines, amounts, order and
     * numbers it got before branches existed. Everything runs outside the
     * branch access scope: the close must see every branch's lines whoever
     * the actor is.
     */
    public function close(self $next, User $actor, ?string $closeReason = null): void
    {
        BranchContext::unrestricted(fn () => DB::transaction(function () use ($next, $actor, $closeReason) {
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
                        "\"{$year->name}\" does not end until {$year->end_date->toDateString()}. Closing it early needs the owner and a written reason."
                    );
                }

                // The same owner-only key the close route requires, repeated
                // here because close() is also reachable outside that route.
                if (! $actor->can('fiscal_year.close_archive')) {
                    throw new AuthorizationException('You do not have permission to close a fiscal year before it has ended.');
                }
            }

            // Perpetual inventory first: nothing below posts to AS12/AS13/
            // AS14, so checking before any posting fails fast and leaves no
            // half-written close behind (the transaction would roll it back
            // anyway, but there is no reason to do the work).
            $endDate = $year->end_date->toDateString();
            $reconciled = InventoryReconciliation::assertReconciled($year, $endDate);

            // I8 per branch; see relock() for why a single branch skips it.
            if (Branch::query()->count() > 1) {
                InventoryReconciliation::assertBranchesReconciled($year, $endDate);
            }

            FixedAsset::postDepreciationForFiscalYear($year, $actor);

            // Read after depreciation, which posts into a branch of its own
            // and must be swept with that branch.
            $branchIds = $year->branchIdsForYearEnd();
            $year->assertBranchBooksCanClose($branchIds);

            $year->postStockTradingEntries($actor, $branchIds);
            $year->postClosingEntries($actor, $branchIds);
            $year->postOpeningBalances($nextYear, $actor, $branchIds);

            FiscalYearInventorySnapshot::rewriteFor($year, $endDate, $reconciled);

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
        }));
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
     * Trading items only: the closing value is
     * StockCosting::totalPeriodicClosingValue(), which leaves out Raw
     * Material and Finished Good items. Their value is already in AS12/AS14
     * through real-time journals, so counting them here as well would put
     * the same stock on the balance sheet twice and overstate gross profit
     * by its value (MANUFACTURING MC9).
     *
     * Both directions are handled rather than assuming a debit balance: a
     * tenant running on allow_negative_stock can hold a negative stock
     * position, and a negative line is rightly refused by
     * JournalVoucher::write().
     *
     * Per branch (plan 12, section 5.6): each voucher carries one pair per
     * branch whose figure is not zero, Main first, each pair tagged to its
     * branch, so each branch balances on its own. Opening stock is the
     * branch's own "Stock in Hand" balance; closing stock comes from
     * periodicClosingStockByBranch(). A single-branch company gets the same
     * one pair per voucher it always did.
     *
     * @param  list<int>  $branchIds  From branchIdsForYearEnd(), Main first.
     */
    private function postStockTradingEntries(User $actor, array $branchIds): void
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
        $mainBranchId = $branchIds[0];

        $openingLines = [];

        foreach ($this->netBalancesByBranch($stockInHand, $branchIds) as $branchId => $openingStock) {
            if (! $openingStock->isZero()) {
                array_push($openingLines, ...self::tradingPair($openingStock, $openingStockAccount->id, $stockInHand->id, 'Opening stock', $branchId));
            }
        }

        if ($openingLines !== []) {
            JournalVoucher::write(
                $this,
                VoucherType::ClosingEntry,
                $endDate,
                "Opening stock transferred to the trading account for {$this->name}",
                null,
                $actor,
                $openingLines,
                null,
                $mainBranchId,
            );
        }

        $closingLines = [];

        foreach ($this->periodicClosingStockByBranch($endDate, $branchIds) as $branchId => $closingStock) {
            if (! $closingStock->isZero()) {
                array_push($closingLines, ...self::tradingPair($closingStock, $stockInHand->id, $closingStockAccount->id, 'Closing stock', $branchId));
            }
        }

        if ($closingLines === []) {
            return;
        }

        JournalVoucher::write(
            $this,
            VoucherType::ClosingEntry,
            $endDate,
            "Closing stock on hand at {$endDate}",
            null,
            $actor,
            $closingLines,
            null,
            $mainBranchId,
        );
    }

    /**
     * One balanced trading pair for one branch: Dr $debitAccountId / Cr
     * $creditAccountId when $net is positive, the other way round when it is
     * negative, always in the line order the pair was written in before
     * branches existed.
     *
     * @return list<array{account_id: int, debit: string, credit: string, narration: string, branch_id: int}>
     */
    private static function tradingPair(Money $net, int $debitAccountId, int $creditAccountId, string $narration, int $branchId): array
    {
        $amount = $net->abs()->toString();

        [$debitId, $creditId] = $net->isPositive()
            ? [$debitAccountId, $creditAccountId]
            : [$creditAccountId, $debitAccountId];

        return [
            ['account_id' => $debitId, 'debit' => $amount, 'credit' => '0.00', 'narration' => $narration, 'branch_id' => $branchId],
            ['account_id' => $creditId, 'debit' => '0.00', 'credit' => $amount, 'narration' => $narration, 'branch_id' => $branchId],
        ];
    }

    /**
     * Periodic closing stock per branch, keyed in $branchIds order.
     *
     * The company figure is StockCosting::totalPeriodicClosingValue() over
     * every store, exactly as before. Each other branch is the sum of its
     * stores' values (quantity per store, company average cost, plan 12
     * section 5.5), and Main takes the company figure minus all of them.
     * Rounding happens per item and per store, so a straight per-branch sum
     * could differ from the company figure by a paisa or two; giving Main the
     * remainder keeps invariant I4 (branches sum to the company value) exact
     * and the company's closing stock identical to what it was. Main also
     * absorbs any movement without a store.
     *
     * @param  list<int>  $branchIds  Main first.
     * @return array<int, Money>
     */
    private function periodicClosingStockByBranch(string $endDate, array $branchIds): array
    {
        $companyValue = StockCosting::totalPeriodicClosingValue($endDate);
        $mainBranchId = $branchIds[0];

        if (count($branchIds) === 1) {
            return [$mainBranchId => $companyValue];
        }

        $otherBranchIds = array_slice($branchIds, 1);

        $storeIdsByBranch = Store::query()
            ->whereIn('branch_id', $otherBranchIds)
            ->orderBy('id')
            ->get(['id', 'branch_id'])
            ->groupBy('branch_id')
            ->map(fn ($stores) => $stores->modelKeys());

        $otherValues = [];

        foreach ($otherBranchIds as $branchId) {
            $otherValues[$branchId] = Money::sum(array_map(
                fn (int $storeId): Money => StockCosting::totalPeriodicClosingValue($endDate, $storeId),
                $storeIdsByBranch->get($branchId, []),
            ));
        }

        // Union, not spread: a spread would renumber the integer branch keys.
        return [$mainBranchId => $companyValue->minus(Money::sum($otherValues))] + $otherValues;
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
     *
     * Per branch (plan 12, section 5.6): the net is taken per (account,
     * branch), and each branch's lines are followed by that branch's own
     * "Profit & Loss" line, so each branch's result stays in that branch and
     * each branch balances on its own. Lines are grouped by branch, Main
     * first; within a branch they keep the account order. One voucher as
     * before, still touching only profit-and-loss accounts plus "Profit &
     * Loss", which is what AccountingReportController's sweep detection
     * relies on.
     *
     * @param  list<int>  $branchIds  From branchIdsForYearEnd(), Main first.
     */
    private function postClosingEntries(User $actor, array $branchIds, ?string $narration = null): void
    {
        $plAccount = Account::where('name', 'Profit & Loss')->firstOrFail();
        $accounts = $this->accountsWhereHeadIsProfitAndLoss(true);

        $netsByAccount = [];

        foreach ($accounts as $account) {
            $netsByAccount[$account->id] = $this->netBalancesByBranch($account, $branchIds);
        }

        $lines = [];

        foreach ($branchIds as $branchId) {
            $branchLines = [];
            $totalZeroingDebit = Money::zero();
            $totalZeroingCredit = Money::zero();

            foreach ($accounts as $account) {
                $net = $netsByAccount[$account->id][$branchId];

                if ($net->isZero()) {
                    continue;
                }

                $amount = $net->abs();

                if ($net->isPositive()) {
                    $branchLines[] = ['account_id' => $account->id, 'debit' => '0.00', 'credit' => $amount->toString(), 'narration' => 'Year-end closing', 'branch_id' => $branchId];
                    $totalZeroingCredit = $totalZeroingCredit->plus($amount);
                } else {
                    $branchLines[] = ['account_id' => $account->id, 'debit' => $amount->toString(), 'credit' => '0.00', 'narration' => 'Year-end closing', 'branch_id' => $branchId];
                    $totalZeroingDebit = $totalZeroingDebit->plus($amount);
                }
            }

            if (! $branchLines) {
                continue;
            }

            $netProfit = $totalZeroingDebit->minus($totalZeroingCredit);

            if (! $netProfit->isZero()) {
                $amount = $netProfit->abs()->toString();

                $branchLines[] = $netProfit->isPositive()
                    ? ['account_id' => $plAccount->id, 'debit' => '0.00', 'credit' => $amount, 'narration' => 'Net profit for the year', 'branch_id' => $branchId]
                    : ['account_id' => $plAccount->id, 'debit' => $amount, 'credit' => '0.00', 'narration' => 'Net loss for the year', 'branch_id' => $branchId];
            }

            array_push($lines, ...$branchLines);
        }

        if (! $lines) {
            return;
        }

        JournalVoucher::write(
            $this,
            VoucherType::ClosingEntry,
            $this->end_date->toDateString(),
            $narration ?? "Year-end closing entries for {$this->name}",
            null,
            $actor,
            $lines,
            null,
            $branchIds[0],
        );
    }

    /**
     * Per branch (plan 12, section 5.6): one line per (account, branch),
     * grouped by branch with Main first, each tagged to its branch, so each
     * branch's balance sheet (its Inter-Branch Clearing balance included)
     * carries forward on its own.
     *
     * Inter-Branch Clearing (BR19) is the one exception: with more than one
     * branch, each branch's balance is carried forward split per
     * counterparty branch, one line per non-zero (branch, counterparty) pair
     * carrying counterparty_branch_id, so the inter-branch balances report
     * can still say who owes whom after a year end, and the next year end
     * splits the same way again (the opening lines are part of the year's
     * lines). Lines with no counterparty (carried forward before BR19) stay
     * one unattributed line per branch. The split partitions exactly the
     * lines the branch's net is summed from, so each branch's lines still
     * add up to that net: I1 per branch and the balanced check below are
     * unaffected. With Main alone the split is skipped altogether (no extra
     * query), so that voucher is exactly what it was; with several branches
     * and no counterparty anywhere each branch gets the one unattributed
     * line it got before.
     *
     * @param  list<int>  $branchIds  From branchIdsForYearEnd(), Main first.
     */
    private function postOpeningBalances(self $next, User $actor, array $branchIds): void
    {
        $accounts = $this->accountsWhereHeadIsProfitAndLoss(false);

        $netsByAccount = [];

        foreach ($accounts as $account) {
            $netsByAccount[$account->id] = $this->netBalancesByBranch($account, $branchIds);
        }

        $clearingAccount = count($branchIds) > 1
            ? $accounts->first(fn (Account $account): bool => $account->code === BranchAccounts::CLEARING_ACCOUNT_CODE)
            : null;
        $clearingByCounterparty = $clearingAccount === null
            ? []
            : $this->clearingNetsByCounterparty($clearingAccount, $branchIds, $netsByAccount[$clearingAccount->id]);

        $lines = [];

        foreach ($branchIds as $branchId) {
            foreach ($accounts as $account) {
                if ($clearingAccount !== null && $account->id === $clearingAccount->id) {
                    foreach ($clearingByCounterparty[$branchId] ?? [] as [$counterpartyId, $net]) {
                        $amount = $net->abs()->toString();

                        $lines[] = [
                            'account_id' => $account->id,
                            'debit' => $net->isPositive() ? $amount : '0.00',
                            'credit' => $net->isPositive() ? '0.00' : $amount,
                            'narration' => 'Opening balance',
                            'branch_id' => $branchId,
                            ...($counterpartyId === InterBranchBalances::UNATTRIBUTED ? [] : ['counterparty_branch_id' => $counterpartyId]),
                        ];
                    }

                    continue;
                }

                $net = $netsByAccount[$account->id][$branchId];

                if ($net->isZero()) {
                    continue;
                }

                $amount = $net->abs()->toString();

                $lines[] = $net->isPositive()
                    ? ['account_id' => $account->id, 'debit' => $amount, 'credit' => '0.00', 'narration' => 'Opening balance', 'branch_id' => $branchId]
                    : ['account_id' => $account->id, 'debit' => '0.00', 'credit' => $amount, 'narration' => 'Opening balance', 'branch_id' => $branchId];
            }
        }

        // Every voucher balances within each branch (invariant I1), so once
        // the sweep has zeroed a branch's profit-and-loss accounts, that
        // branch's balance-sheet accounts sum to zero too. A branch that does
        // not means a voucher broke I1; refuse the close with a message that
        // says so, rather than the posting engine's generic one. Only checked
        // with more than one branch: with Main alone the company-wide balance
        // check below covers it, exactly as before.
        if (count($branchIds) > 1) {
            $this->assertEachBranchCarriesForwardBalanced($lines);
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
            null,
            $branchIds[0],
        );
    }

    /**
     * This year's Inter-Branch Clearing balance of each branch split per
     * counterparty branch (BR19), in postOpeningBalances()'s line order, read
     * outside the branch access scope like netBalance().
     *
     * The buckets partition the very lines netBalance($clearing, $branchId)
     * sums (same account, branch and fiscal year), so they always add up to
     * it; that is re-checked against $netsByBranch and a difference refuses
     * the close rather than carrying forward a balance that does not match
     * the books.
     *
     * @param  list<int>  $branchIds  Main first.
     * @param  array<int, Money>  $netsByBranch  netBalancesByBranch() of the clearing account.
     * @return array<int, list<array{0: int, 1: Money}>> branch id => [counterparty id or UNATTRIBUTED, net]
     */
    private function clearingNetsByCounterparty(Account $clearingAccount, array $branchIds, array $netsByBranch): array
    {
        $byPair = BranchContext::unrestricted(fn (): array => InterBranchBalances::netsByPair(
            (int) $clearingAccount->id,
            fn ($query) => $query->where('fiscal_year_id', $this->id),
        ));

        $split = [];

        foreach ($branchIds as $branchId) {
            $buckets = InterBranchBalances::ordered($byPair[$branchId] ?? [], $branchIds);
            $total = Money::sum(array_map(fn (array $bucket): Money => $bucket[1], $buckets));

            if (! $total->isEqualTo($netsByBranch[$branchId])) {
                throw new InvalidArgumentException(sprintf(
                    '"%s" cannot be closed: the %s balance of branch #%d split by counterparty (%s) does not match its total (%s). Contact support.',
                    $this->name,
                    BranchBalancer::CLEARING_ACCOUNT_NAME,
                    $branchId,
                    $total->toString(),
                    $netsByBranch[$branchId]->toString(),
                ));
            }

            $split[$branchId] = $buckets;
        }

        return $split;
    }

    /**
     * @param  list<array{debit: string, credit: string, branch_id: int}>  $lines
     */
    private function assertEachBranchCarriesForwardBalanced(array $lines): void
    {
        $imbalances = BranchBalancer::imbalances($lines);

        if ($imbalances === []) {
            return;
        }

        $branchId = array_key_first($imbalances);
        $branchName = Branch::query()->whereKey($branchId)->value('name') ?? "#{$branchId}";

        throw new InvalidArgumentException(sprintf(
            '"%s" cannot be closed: the books of branch %s are out of balance by %s. An entry in this year does not balance within each branch; correct it before closing.',
            $this->name,
            $branchName,
            $imbalances[$branchId]->abs()->toString(),
        ));
    }

    /**
     * The branches the year-end entries are written for: Main first (always,
     * as before branches existed), then by id every other branch that has a
     * journal line in this year (its opening balances included) or owns a
     * store (its closing stock is valued even when it posted nothing).
     * Inactive branches count too: their balances still exist.
     *
     * One query when only Main exists. The probes are raw subqueries, so no
     * branch access scope applies to them.
     *
     * @return list<int>
     */
    private function branchIdsForYearEnd(): array
    {
        $mainBranchId = BranchContext::mainBranchId();

        $otherBranchIds = Branch::query()
            ->whereKeyNot($mainBranchId)
            ->where(function (Builder $query): void {
                $query
                    ->whereExists(fn (QueryBuilder $lines) => $lines
                        ->selectRaw('1')
                        ->from('journal_voucher_lines')
                        ->join('journal_vouchers', 'journal_vouchers.id', '=', 'journal_voucher_lines.journal_voucher_id')
                        ->whereColumn('journal_voucher_lines.branch_id', 'branches.id')
                        ->where('journal_vouchers.fiscal_year_id', $this->id))
                    ->orWhereExists(fn (QueryBuilder $stores) => $stores
                        ->selectRaw('1')
                        ->from('stores')
                        ->whereColumn('stores.branch_id', 'branches.id'));
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return [$mainBranchId, ...$otherBranchIds];
    }

    /**
     * Refuses the close when the branches' books cannot be closed
     * (plan 12, section 5.6). Runs before any year-end entry is written.
     *
     * Invariant I3: "Inter-Branch Clearing" nets to zero across all
     * branches, since every clearing debit in one branch has its credit in
     * another. A non-zero total means someone posted to the clearing account
     * by hand, and carrying that forward would leave the branches owing each
     * other money that nobody owes. Its stock half: "Stock Transferred to
     * Branches" equals "Stock Received from Branches" for the year, or one
     * branch's gross profit carries stock the other never took in. All three
     * accounts are found by their protected codes (BranchAccounts); a
     * missing one counts as zero.
     *
     * BR18: refused first of all while any stock transfer between branches
     * is in transit. Its dispatch voucher is posted and its receipt voucher
     * is not, so the clearing account and the two trading accounts cannot
     * net to zero yet, and the goods would sit in neither branch's closing
     * stock; checked before I3 so the user is told what to do rather than
     * shown an imbalance. Such a transfer always spans two branches, so the
     * single-branch early return below holds for it too. Read outside the
     * branch access scope (a company-wide fact) with a plain read: the
     * caller already holds the fiscal-year row lock, and every receive or
     * cancel needs that same lock to post its voucher, so none can commit
     * between this read and the close. A lock on the transfers here would
     * invert the receive/cancel lock order (transfer, then fiscal year).
     *
     * relock() runs it too, before its supplementary sweep, with $action
     * 'relocked' so the refusal names what was being attempted.
     *
     * @param  list<int>  $branchIds  From branchIdsForYearEnd(), Main first.
     * @param  'closed'|'relocked'  $action
     */
    private function assertBranchBooksCanClose(array $branchIds, string $action = 'closed'): void
    {
        if (count($branchIds) === 1) {
            return;
        }

        $attempting = $action === 'relocked' ? 'relocking' : 'closing';

        $inTransit = BranchContext::unrestricted(fn () => StockTransfer::query()
            ->where('status', StockTransfer::STATUS_IN_TRANSIT)
            ->orderBy('id')
            ->pluck('id'));

        if ($inTransit->isNotEmpty()) {
            throw new InvalidArgumentException(sprintf(
                '"%s" cannot be %s: %d stock transfer(s) between branches are still in transit (%s). Receive or cancel them before %s.',
                $this->name,
                $action,
                $inTransit->count(),
                $inTransit->take(5)->map(fn (mixed $id): string => "#{$id}")->implode(', '),
                $attempting,
            ));
        }

        $accounts = Account::query()
            ->whereIn('code', [
                BranchAccounts::CLEARING_ACCOUNT_CODE,
                BranchAccounts::STOCK_TRANSFERRED_CODE,
                BranchAccounts::STOCK_RECEIVED_CODE,
            ])
            ->get()
            ->keyBy('code');

        $clearingAccount = $accounts->get(BranchAccounts::CLEARING_ACCOUNT_CODE);

        if ($clearingAccount !== null) {
            $clearingNet = $this->netBalance($clearingAccount);

            if (! $clearingNet->isZero()) {
                throw new InvalidArgumentException(sprintf(
                    '"%s" cannot be %s: %s does not net to zero across branches (%s %s). Correct the entries posted to it before %s.',
                    $this->name,
                    $action,
                    BranchBalancer::CLEARING_ACCOUNT_NAME,
                    $clearingNet->abs()->toString(),
                    $clearingNet->isPositive() ? 'debit' : 'credit',
                    $attempting,
                ));
            }
        }

        // I3, stock half: every transfer credits the sender's Stock
        // Transferred and debits the receiver's Stock Received by the same
        // amount, so company-wide the two nets cancel (debit minus credit
        // sums to zero). A missing account counts as zero, so a company with
        // neither account, or no transfers, passes.
        $transferredAccount = $accounts->get(BranchAccounts::STOCK_TRANSFERRED_CODE);
        $receivedAccount = $accounts->get(BranchAccounts::STOCK_RECEIVED_CODE);
        $transferredNet = $transferredAccount !== null ? $this->netBalance($transferredAccount) : Money::zero();
        $receivedNet = $receivedAccount !== null ? $this->netBalance($receivedAccount) : Money::zero();

        if (! $transferredNet->plus($receivedNet)->isZero()) {
            throw new InvalidArgumentException(sprintf(
                '"%s" cannot be %s: %s (%s) does not equal %s (%s) across branches. Correct the entries posted to them before %s.',
                $this->name,
                $action,
                BranchAccounts::STOCK_TRANSFERRED_NAME,
                $transferredNet->negated()->toString(),
                BranchAccounts::STOCK_RECEIVED_NAME,
                $receivedNet->toString(),
                $attempting,
            ));
        }
    }

    /**
     * One account's net balance in this year per branch, keyed in
     * $branchIds order. With Main alone this is the single company-wide
     * figure (the same one query as before branches existed): no other
     * branch has a line in this year, so it is Main's figure exactly.
     *
     * @param  list<int>  $branchIds  Main first.
     * @return array<int, Money>
     */
    private function netBalancesByBranch(Account $account, array $branchIds): array
    {
        if (count($branchIds) === 1) {
            return [$branchIds[0] => $this->netBalance($account)];
        }

        $nets = [];

        foreach ($branchIds as $branchId) {
            $nets[$branchId] = $this->netBalance($account, $branchId);
        }

        return $nets;
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
     *
     * $branchId narrows the sum to that branch's lines (plan 12, section
     * 5.6); null is the whole company, exactly the figure this returned
     * before branches existed. Always the whole truth: the query runs
     * outside the branch access scope, so a restricted actor never gets a
     * partial balance from here.
     */
    public function netBalance(Account $account, ?int $branchId = null): Money
    {
        $cast = DB::connection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        $netScaled = BranchContext::unrestricted(fn () => JournalVoucherLine::query()
            ->where('account_id', $account->id)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->whereHas('journalVoucher', fn ($query) => $query->where('fiscal_year_id', $this->id))
            ->selectRaw(
                "COALESCE(SUM(CAST(ROUND(debit * 100) AS {$cast})), 0) - COALESCE(SUM(CAST(ROUND(credit * 100) AS {$cast})), 0) as net_scaled"
            )
            ->value('net_scaled'));

        return Money::of(BigDecimal::of((int) $netScaled)->dividedBy(100, 2, RoundingMode::Unnecessary));
    }
}
