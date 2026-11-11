<?php

namespace App\Models;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Concerns\BelongsToBranch;
use App\Support\Branches\BranchBalancer;
use App\Support\Branches\BranchContext;
use App\Support\Branches\SeriesNumber;
use App\Support\ClosedFiscalYearGuard;
use App\Support\Money\Money;
use App\Support\ReversalNotice;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * branch_id is the voucher's origin branch (plan 12, section 5.3): the hub
 * its Inter-Branch Clearing lines meet at, and the branch lists, numbering
 * and access read. It is fillable on purpose: mass assignment drops an
 * unlisted column, and the column default (Main) would then hide the loss.
 *
 * series_branch_id (plan 12, BR20) is the series the number came from: 0 for
 * the shared series, else the header branch in a per_branch year. Never
 * print voucher_number on its own; use seriesNumber() / printedNumber().
 */
#[Fillable(['fiscal_year_id', 'voucher_type', 'voucher_number', 'series_branch_id', 'date', 'narration', 'reason', 'status', 'created_by', 'reversal_of_id', 'branch_id'])]
class JournalVoucher extends Model
{
    use BelongsToBranch;

    /**
     * Posted vouchers are immutable ledger records (audit JE-01). The only
     * permitted update is the cancellation bookkeeping reverse() performs:
     * `status` and `reversal_of_id`. Deleting is never permitted.
     */
    protected static function booted(): void
    {
        static::updating(function (self $voucher): void {
            $forbidden = array_diff(array_keys($voucher->getDirty()), ['status', 'reversal_of_id', 'updated_at']);

            if ($forbidden !== []) {
                throw new LogicException('A posted journal voucher is immutable; only its status and reversal link may change. Post a reversal instead.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A journal voucher can never be deleted. Post a reversal instead.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'voucher_type' => VoucherType::class,
            'date' => 'date:Y-m-d',
            'branch_id' => 'integer',
            'series_branch_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<JournalVoucherLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalVoucherLine::class);
    }

    /**
     * The original voucher this one reverses, when this voucher was created by
     * reverse(). Null on every ordinary posting.
     *
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * The Reversal voucher that cancelled this one, if any. HasOne rather than
     * HasMany because journal_vouchers.reversal_of_id is unique: a voucher can
     * only ever be reversed once.
     *
     * @return HasOne<JournalVoucher, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /**
     * A restricted user sees a voucher whose origin branch is theirs, and
     * also one that only has lines in their branches (branch A receiving
     * cash for branch B's invoice puts B's receivable line on A's voucher;
     * B's users must still find it). The lines themselves stay limited to
     * their branches by JournalVoucherLine's own scope (plan 12, section 5.7).
     * Overrides BelongsToBranch's plain branch_id predicate; only ever runs
     * for restricted users.
     *
     * @param  Builder<static>  $query
     * @param  list<int>  $branchIds
     */
    public function applyBranchAccessConstraint(Builder $query, array $branchIds): void
    {
        $voucherKey = $this->qualifyColumn($this->getKeyName());

        $query->where(function (Builder $visible) use ($branchIds, $voucherKey): void {
            $visible->whereIn($this->qualifyColumn('branch_id'), $branchIds)
                ->orWhereExists(function (QueryBuilder $lines) use ($branchIds, $voucherKey): void {
                    $lines->selectRaw('1')
                        ->from('journal_voucher_lines')
                        ->whereColumn('journal_voucher_lines.journal_voucher_id', $voucherKey)
                        ->whereIn('journal_voucher_lines.branch_id', $branchIds);
                });
        });
    }

    /**
     * The one user-facing entry point for posting a journal voucher.
     * Resolves the target fiscal year (defaults to the currently open one),
     * gates posting into a closed year behind ClosedFiscalYearGuard (only
     * postable once a closed year has been deliberately reopened for
     * correction - see FiscalYear::isOpenForCorrection() - and even then
     * only by a user holding fiscal_year.edit, with a reason), and rolls a closed-year
     * correction's effect forward through any already-created subsequent
     * fiscal years.
     *
     * The voucher date must fall inside whichever fiscal year is resolved
     * (enforced in write()); a date from a different year is refused rather
     * than quietly filed under the wrong one.
     *
     * $logCorrection is false only for callers (Purchase::post()) that wrap
     * this voucher in a document of their own and log a more specific
     * correction message themselves once that document exists - leaving it
     * true here as well would write two correction rows for the one
     * logical posting, and ActivityLog has no stable order to pick the
     * right one back out by.
     *
     * $lineNarration, when given, is called with the new voucher (numbered,
     * before any line exists) and its result becomes every line's narration,
     * except Inter-Branch Clearing lines, which keep their own narration.
     * Documents whose ledger narration quotes their own number ("S-12 -
     * Cash Settlement") need the voucher number first; they used to post
     * the lines and then rewrite them with a bulk query-builder update,
     * which only worked because a bulk update skips the model guard that
     * keeps posted lines immutable (flags G-22).
     *
     * Branches (plan 12, section 5.3): $header['branch_id'] is the origin
     * branch; when missing it is BranchContext::postingBranchId() for
     * $actor (their selected or default branch, else Main), which is how
     * every caller written before branches existed keeps posting into Main.
     * Each line may carry its own branch_id (default: the origin) and
     * counterparty_branch_id. Every branch's lines must balance on their own
     * (invariant I1); a caller that legitimately spans branches (a receipt
     * settling another branch's invoice, a cross-branch transfer) passes
     * $autoBalance and BranchBalancer adds the Inter-Branch Clearing lines.
     * A restricted actor may only post into branches they can access.
     *
     * @param  array{voucher_type?: string, date: string, narration: string, reason?: string, fiscal_year_id?: int, branch_id?: int|null}  $header
     * @param  array<int, array{account_id: int, debit?: float|string, credit?: float|string, narration?: string, branch_id?: int|null, counterparty_branch_id?: int|null}>  $lines
     * @param  (Closure(self): string)|null  $lineNarration
     *
     * @throws AuthorizationException When $actor may not post into one of the branches.
     */
    public static function post(array $header, array $lines, User $actor, bool $logCorrection = true, ?Closure $lineNarration = null, bool $autoBalance = false): self
    {
        return DB::transaction(function () use ($header, $lines, $actor, $logCorrection, $lineNarration, $autoBalance) {
            // Row-locked so a concurrent FiscalYear::close() (which takes the
            // same lock) either finishes first and this sees a Closed year, or
            // waits for this posting to commit (audit JE-04).
            $fiscalYear = isset($header['fiscal_year_id'])
                ? FiscalYear::query()->whereKey($header['fiscal_year_id'])->lockForUpdate()->firstOrFail()
                : FiscalYear::query()->where('status', FiscalYearStatus::Open)->lockForUpdate()->firstOrFail();

            $reason = $header['reason'] ?? null;
            $isOverride = $fiscalYear->status === FiscalYearStatus::Closed;

            // Only postable when $fiscalYear is the open year, or a closed
            // year that's been deliberately reopened for correction (see
            // FiscalYear::isOpenForCorrection()) - never a plain closed
            // year, fiscal_year.edit+reason or not. See ClosedFiscalYearGuard's
            // docblock for the locked design decision this implements.
            ClosedFiscalYearGuard::ensurePostable($fiscalYear, $reason);

            // fiscal_year.edit, not the owner-only close_archive key: posting
            // a correction is day-to-day bookkeeping a trusted accountant
            // role may be granted, reopening the year is not.
            if ($isOverride && ! $actor->can('fiscal_year.edit')) {
                throw new AuthorizationException('You do not have permission to post into a reopened fiscal year.');
            }

            $branchId = static::branchIdOrNull($header['branch_id'] ?? null, 'The journal voucher names an invalid branch.')
                ?? BranchContext::postingBranchId($actor);

            // Clearing lines only ever join branches already on the voucher,
            // so checking the origin and the caller's line branches covers
            // the whole posting.
            static::assertActorMayPostTo($actor, [$branchId, ...static::lineBranchIds($lines, $branchId)]);
            static::assertBranchesUsable($branchId, $lines);

            $voucher = static::write(
                $fiscalYear,
                VoucherType::from($header['voucher_type'] ?? VoucherType::Journal->value),
                $header['date'],
                $header['narration'],
                $reason,
                $actor,
                $lines,
                $lineNarration,
                $branchId,
                $autoBalance,
            );

            if ($isOverride) {
                static::rollForward($voucher, $actor);

                if ($logCorrection) {
                    ClosedFiscalYearGuard::logCorrection($fiscalYear, $reason, "Journal voucher #{$voucher->seriesNumber()}: {$header['narration']}");
                }
            }

            return $voucher;
        });
    }

    /**
     * Shared low-level writer: checks the date against the fiscal year,
     * validates and normalises double-entry shape/balance, atomically claims
     * the next voucher number, and creates the header + lines. Used directly
     * (bypassing post()'s fiscal-year resolution and closed-year gate) by
     * FiscalYear::close() and the roll-forward cascade, both of which target a
     * specific fiscal year for system-generated bookkeeping rather than a
     * user-initiated posting.
     *
     * $branchId is the origin branch; null means
     * BranchContext::postingBranchId($actor), the same default post() uses.
     * System callers that know the branch (reversal, roll-forward, year
     * close) pass it. Branch access is not checked here: write() is the
     * system path, and post() and reverse() check the actor themselves.
     *
     * @param  array<int, array{account_id: int, debit?: float|string, credit?: float|string, narration?: string, branch_id?: int|null, counterparty_branch_id?: int|null}>  $lines
     * @param  (Closure(self): string)|null  $lineNarration  See post().
     * @param  bool  $autoBalance  See post().
     */
    public static function write(
        FiscalYear $fiscalYear,
        VoucherType $type,
        string $date,
        string $narration,
        ?string $reason,
        User $actor,
        array $lines,
        ?Closure $lineNarration = null,
        ?int $branchId = null,
        bool $autoBalance = false,
    ): self {
        $date = static::assertDateInsideFiscalYear($fiscalYear, $date);
        $branchId ??= BranchContext::postingBranchId($actor);
        $lines = static::validateLines($lines, $branchId, $autoBalance);

        // Per-branch numbering (BR20): the header branch picks the series in
        // a per_branch year; a shared year resolves to 0 with no query.
        $seriesBranch = SeriesNumber::seriesBranchFor($fiscalYear, $branchId);
        $seriesBranchId = SeriesNumber::seriesBranchId($seriesBranch);

        // The year the caller holds may be an unlocked copy (depreciation,
        // disposal). Locked exclusively (a no-op when the caller already holds
        // it, as post() and reverse() do) so a concurrent scope change waits
        // until this posting commits, and so a flow that later takes the
        // exclusive lock itself (disposal) never upgrades from a share lock.
        // The re-check refuses to number from the wrong series if the scope
        // flipped between the caller's read and now.
        $lockedScope = FiscalYear::query()->whereKey($fiscalYear->getKey())->lockForUpdate()->value('numbering_scope');

        if (($lockedScope === FiscalYear::NUMBERING_PER_BRANCH) !== ($seriesBranch !== null)) {
            throw new InvalidArgumentException(
                "The numbering of \"{$fiscalYear->name}\" was changed while this was posting. Please try again."
            );
        }

        $voucher = static::create([
            'fiscal_year_id' => $fiscalYear->id,
            'voucher_type' => $type,
            'series_branch_id' => $seriesBranchId,
            'voucher_number' => VoucherSequence::claimNext($fiscalYear, $type, $seriesBranchId),
            'date' => $date,
            'narration' => $narration,
            'reason' => $reason,
            'status' => 'posted',
            'created_by' => $actor->id,
            'branch_id' => $branchId,
        ]);

        // Only a branch series: a shared voucher must serialise exactly as
        // before, without a series_branch key.
        if ($seriesBranch !== null) {
            $voucher->setRelation('seriesBranch', $seriesBranch);
        }

        if ($lineNarration !== null) {
            $resolved = $lineNarration($voucher);

            if (mb_strlen($resolved) > self::MAX_NARRATION_LENGTH) {
                throw new InvalidArgumentException('A line narration can be at most '.self::MAX_NARRATION_LENGTH.' characters.');
            }

            // The system's Inter-Branch Clearing lines keep their own
            // narration; the caller's narration is for the caller's lines.
            $lines = array_map(
                fn (array $line): array => ($line['narration'] ?? null) === BranchBalancer::CLEARING_NARRATION
                    && ($line['counterparty_branch_id'] ?? null) !== null
                    ? $line
                    : [...$line, 'narration' => $resolved],
                $lines,
            );
        }

        $voucher->lines()->createMany($lines);

        return $voucher;
    }

    /**
     * A voucher may only be dated inside the fiscal year it posts into.
     *
     * Nothing checked this before: post() resolved FiscalYear::current() and
     * wrote whatever date the form sent, so a bill dated Asar 30 posted after
     * the Shrawan 1 auto-rollover landed in the NEW year's ledger while the
     * date-filtered VAT book and Day Book still reported it in the already
     * filed period (audit P0-11). Enforced in write() rather than post() so
     * the system-bookkeeping callers are covered too; they each target an
     * explicit year with a date inside it (FiscalYear::close() uses that
     * year's end_date and the next year's start_date, rollForward() and
     * FixedAsset's depreciation run the same way), so this is a no-op for
     * them.
     *
     * @return string The date normalised to Y-m-d, which is what gets stored.
     */
    private static function assertDateInsideFiscalYear(FiscalYear $fiscalYear, string $date): string
    {
        $posted = CarbonImmutable::parse($date)->startOfDay();
        $start = $fiscalYear->start_date->copy()->startOfDay();
        $end = $fiscalYear->end_date->copy()->startOfDay();

        if ($posted->lessThan($start) || $posted->greaterThan($end)) {
            throw new InvalidArgumentException(sprintf(
                'The date %s is outside fiscal year %s (%s to %s).',
                $posted->toDateString(),
                $fiscalYear->name,
                $start->toDateString(),
                $end->toDateString(),
            ));
        }

        return $posted->toDateString();
    }

    /**
     * The largest amount a line can hold: journal_voucher_lines.debit and
     * credit are DECIMAL(20,2), so 18 digits before the point (flags G-20).
     */
    public const MAX_LINE_AMOUNT = '999999999999999999.99';

    /**
     * The longest narration a line can hold (a VARCHAR(255) column).
     */
    private const MAX_NARRATION_LENGTH = 255;

    /**
     * Validates double-entry shape and balance, and returns the lines with
     * every amount normalised to an exact 2-decimal string.
     *
     * Both halves matter. The old check summed raw floats and compared
     * round($sum, 2), which let Dr 333.333 x 3 balance against Cr 999.999 and
     * then handed the unrounded values to MySQL, which rounded each line on
     * its own and stored Dr 999.99 against Cr 1000.00 - an unbalanced voucher
     * that later broke year-end close (audit P0-2). Money::of() refuses
     * anything with more than 2 decimals outright (so the caller is told,
     * rather than silently losing a paisa), the totals are compared exactly,
     * and the normalised strings are what gets stored.
     *
     * Branches: every line comes back with a branch_id (its own, else
     * $headerBranchId) and a counterparty_branch_id (its own, else null).
     * After the company-wide total check, $autoBalance lets BranchBalancer
     * append Inter-Branch Clearing lines, and then each branch must balance
     * on its own (invariant I1). A voucher whose lines all sit in one branch
     * passes the per-branch check exactly when it passes the total check, so
     * it comes back with the same lines, amounts and order as before
     * branches existed, and never reads the clearing account.
     *
     * @param  array<int, array{account_id: int, debit?: float|string, credit?: float|string, narration?: string, branch_id?: int|null, counterparty_branch_id?: int|null}>  $lines
     * @return list<array{account_id: int, debit: string, credit: string, narration: string|null, branch_id: int, counterparty_branch_id: int|null}>
     */
    private static function validateLines(array $lines, int $headerBranchId, bool $autoBalance = false): array
    {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('A journal voucher needs at least two lines.');
        }

        $normalised = [];
        $debits = [];
        $credits = [];
        $branchesSeen = [];

        foreach ($lines as $line) {
            // ofNullable so a blank box ('' or null) reads as zero; anything
            // else must parse exactly, with at most two decimals.
            $debit = Money::ofNullable($line['debit'] ?? null) ?? Money::zero();
            $credit = Money::ofNullable($line['credit'] ?? null) ?? Money::zero();

            if ($debit->isNegative() || $credit->isNegative()) {
                throw new InvalidArgumentException('A journal voucher line cannot carry a negative amount; put it on the other side instead.');
            }

            // Checked here as well as in the request, because every module
            // posts through this method, not only the manual journal form
            // (flags G-20); MySQL would otherwise refuse or truncate.
            if ($debit->isGreaterThan(self::MAX_LINE_AMOUNT) || $credit->isGreaterThan(self::MAX_LINE_AMOUNT)) {
                throw new InvalidArgumentException('A journal voucher line amount is larger than the ledger can hold.');
            }

            if (mb_strlen((string) ($line['narration'] ?? '')) > self::MAX_NARRATION_LENGTH) {
                throw new InvalidArgumentException('A line narration can be at most '.self::MAX_NARRATION_LENGTH.' characters.');
            }

            // Also rejects a zero line (neither side positive): a line that
            // moves nothing is never a real posting, and silently keeping it
            // would let a "balanced" voucher consist entirely of nothing.
            if ($debit->isPositive() === $credit->isPositive()) {
                throw new InvalidArgumentException('Each line must have exactly one of debit or credit greater than zero.');
            }

            $debits[] = $debit;
            $credits[] = $credit;

            $lineBranchId = static::branchIdOrNull($line['branch_id'] ?? null) ?? $headerBranchId;
            $counterpartyBranchId = static::branchIdOrNull($line['counterparty_branch_id'] ?? null);

            // A counterparty names the other end of a clearing pair, so it can
            // never be the line's own branch. Callers set it only on clearing
            // lines (BranchBalancer, or reverse()/rollForward() replaying them).
            if ($counterpartyBranchId !== null && $counterpartyBranchId === $lineBranchId) {
                throw new InvalidArgumentException('A journal voucher line cannot name its own branch as the counterparty branch.');
            }

            $branchesSeen[$lineBranchId] = true;

            $normalised[] = [
                'account_id' => $line['account_id'],
                'debit' => $debit->toString(),
                'credit' => $credit->toString(),
                'narration' => $line['narration'] ?? null,
                'branch_id' => $lineBranchId,
                'counterparty_branch_id' => $counterpartyBranchId,
            ];
        }

        if (! Money::sum($debits)->isEqualTo(Money::sum($credits))) {
            throw new InvalidArgumentException('Total debit must equal total credit.');
        }

        // Parity fast path: with every line in one branch, that branch's
        // balance is the total just checked, and the balancer would add
        // nothing (one branch netting to zero is never a spoke to clear).
        if (count($branchesSeen) === 1) {
            return $normalised;
        }

        if ($autoBalance) {
            $normalised = BranchBalancer::balance($normalised, $headerBranchId);
        }

        static::assertEachBranchBalances($normalised);

        return $normalised;
    }

    /**
     * Invariant I1: within one voucher, each branch's debits equal its
     * credits. Without this a branch's books (trial balance, balance sheet)
     * would not balance even though the company's do.
     *
     * @param  list<array{debit: string, credit: string, branch_id: int}>  $lines
     */
    private static function assertEachBranchBalances(array $lines): void
    {
        $imbalances = BranchBalancer::imbalances($lines);

        if ($imbalances === []) {
            return;
        }

        $branchId = array_key_first($imbalances);
        $net = $imbalances[$branchId];
        $branchName = Branch::query()->whereKey($branchId)->value('name') ?? "#{$branchId}";

        throw new InvalidArgumentException(sprintf(
            'The lines for branch %s do not balance: %s exceeds %s by %s. Each branch in an entry must balance on its own; '
            .'post it with inter-branch balancing to add the clearing lines.',
            $branchName,
            $net->isPositive() ? 'debit' : 'credit',
            $net->isPositive() ? 'credit' : 'debit',
            $net->abs()->toString(),
        ));
    }

    /**
     * A branch id from caller input: null or '' means "not given", anything
     * else must be a positive whole number.
     */
    private static function branchIdOrNull(mixed $value, string $invalidMessage = 'A journal voucher line names an invalid branch.'): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $branchId = (int) $value;

            if ($branchId > 0) {
                return $branchId;
            }
        }

        throw new InvalidArgumentException($invalidMessage);
    }

    /**
     * Every branch post() is about to write into must exist and be active,
     * and every counterparty branch must exist, for unrestricted actors too
     * (the foreign key alone would only surface as a database error).
     * reverse() and rollForward() do not call this: they replay history,
     * which must stay reversible after a branch is deactivated.
     *
     * Main is skipped (it always exists and can never be deactivated), so a
     * Main-only posting adds no query; anything else costs one.
     *
     * @param  array<int, array{branch_id?: int|string|null, counterparty_branch_id?: int|string|null}>  $lines
     */
    private static function assertBranchesUsable(int $headerBranchId, array $lines): void
    {
        $mainId = BranchContext::mainBranchIdOrNull();
        $notMain = fn (int $branchId): bool => $branchId !== $mainId;

        $postingIds = array_values(array_unique(array_filter([$headerBranchId, ...static::lineBranchIds($lines, $headerBranchId)], $notMain)));
        $counterpartyIds = array_values(array_unique(array_filter(
            array_map(fn (array $line): ?int => static::branchIdOrNull($line['counterparty_branch_id'] ?? null), $lines),
            fn (?int $branchId): bool => $branchId !== null && $branchId !== $mainId,
        )));

        if ($postingIds === [] && $counterpartyIds === []) {
            return;
        }

        $found = Branch::query()
            ->whereIn('id', array_values(array_unique([...$postingIds, ...$counterpartyIds])))
            ->get(['id', 'name', 'is_active'])
            ->keyBy('id');

        $problems = [];

        foreach ($postingIds as $branchId) {
            $branch = $found->get($branchId);

            if ($branch === null) {
                $problems[] = "branch #{$branchId} does not exist";
            } elseif (! $branch->is_active) {
                $problems[] = "branch {$branch->name} is inactive";
            }
        }

        foreach ($counterpartyIds as $branchId) {
            if (! $found->has($branchId)) {
                $problems[] = "counterparty branch #{$branchId} does not exist";
            }
        }

        if ($problems !== []) {
            throw new InvalidArgumentException('This entry cannot be posted: '.implode('; ', array_unique($problems)).'.');
        }
    }

    /**
     * The branch each raw line will post into, the way validateLines()
     * resolves it, for the access check in post().
     *
     * @param  array<int, array{branch_id?: int|string|null}>  $lines
     * @return list<int>
     */
    private static function lineBranchIds(array $lines, int $headerBranchId): array
    {
        return array_map(fn (array $line): int => static::branchIdOrNull($line['branch_id'] ?? null) ?? $headerBranchId, $lines);
    }

    /**
     * Invariant I6 on the write side: a restricted actor may only post into
     * branches they can access. A no-op for unrestricted actors and inside
     * BranchContext::unrestricted(), where system code posts on purpose.
     *
     * @param  array<int, int>  $branchIds
     *
     * @throws AuthorizationException
     */
    private static function assertActorMayPostTo(User $actor, array $branchIds): void
    {
        if (BranchContext::accessIsLifted()) {
            return;
        }

        $allowed = BranchContext::allowedBranchIds($actor);

        if ($allowed === null) {
            return;
        }

        foreach (array_unique($branchIds) as $branchId) {
            if (! in_array($branchId, $allowed, true)) {
                throw new AuthorizationException('You do not have access to post into one of the branches on this entry.');
            }
        }
    }

    /**
     * The branch whose series this voucher's number came from (plan 12,
     * BR20). Only meaningful when series_branch_id is not 0; never eager
     * load it blindly (0 matches no branch but still costs a query), use
     * loadSeriesBranches().
     *
     * @return BelongsTo<Branch, $this>
     */
    public function seriesBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'series_branch_id');
    }

    /**
     * The number as people read it: `{n}` in the shared series (exactly the
     * bare voucher_number every screen showed before BR20), `{series_code}-{n}`
     * in a branch series, where the bare number repeats once per branch.
     * See SeriesNumber::format().
     */
    public function seriesNumber(): string
    {
        return SeriesNumber::format($this->voucher_number, $this->seriesCode());
    }

    /**
     * `{prefix}-{n}` in the shared series, `{prefix}-{series_code}-{n}` in a
     * branch series. The one way a document builds its printed number from
     * its voucher (sale invoices, credit and debit notes, purchase numbers,
     * PMT-, SAL-, ADV-, capital invoices, ...).
     */
    public function printedNumber(string $prefix): string
    {
        return SeriesNumber::format($this->voucher_number, $this->seriesCode(), $prefix);
    }

    /**
     * For JSON payloads that used to carry the bare voucher number: the same
     * integer in the shared series (payloads stay byte-identical), the
     * `{series_code}-{n}` string in a branch series.
     */
    public function displayNumber(): int|string
    {
        return (int) $this->series_branch_id === 0 ? (int) $this->voucher_number : $this->seriesNumber();
    }

    /**
     * The series code printed with this voucher's number, or null for the
     * shared series. The code is frozen once a branch series has numbered a
     * voucher (Branch::booted()), so reading it at display time is stable.
     * A branch that somehow lost its code falls back to its id rather than
     * printing a bare, ambiguous number.
     */
    public function seriesCode(): ?string
    {
        $seriesBranchId = (int) $this->series_branch_id;

        if ($seriesBranchId === 0) {
            return null;
        }

        $code = trim((string) $this->seriesBranch?->series_code);

        return $code !== '' ? $code : (string) $seriesBranchId;
    }

    /**
     * Loads seriesBranch for the vouchers that are in a branch series, in one
     * query, and none at all when every voucher is in the shared series (the
     * only case a shared-scope company ever has), so lists can call
     * seriesNumber() per row without N+1 queries.
     *
     * @param  iterable<self|null>  $vouchers
     */
    public static function loadSeriesBranches(iterable $vouchers): void
    {
        $pending = [];

        foreach ($vouchers as $voucher) {
            if ($voucher instanceof self && (int) $voucher->series_branch_id !== 0 && ! $voucher->relationLoaded('seriesBranch')) {
                $pending[] = $voucher;
            }
        }

        if ($pending === []) {
            return;
        }

        $branches = Branch::query()
            ->whereIn('id', array_values(array_unique(array_map(fn (self $voucher): int => (int) $voucher->series_branch_id, $pending))))
            ->get(['id', 'name', 'series_code'])
            ->keyBy('id');

        foreach ($pending as $voucher) {
            $voucher->setRelation('seriesBranch', $branches->get((int) $voucher->series_branch_id));
        }
    }

    /**
     * Replays the voucher's own lines with each account retargeted to
     * "Profit & Loss" when the original account resets to zero every
     * year-end (so its only lasting effect is on retained earnings), or
     * left as-is for a balance-sheet account that carries forward
     * directly. Relabeling accounts never changes the debit/credit
     * amounts, so the replayed line set stays balanced automatically.
     * Posted into every fiscal year after the corrected one, up to and
     * including the currently open one.
     *
     * Every replayed line keeps its branch and counterparty branch, and the
     * adjustment keeps the corrected voucher's origin branch, so each branch
     * stays balanced (retargeting never moves an amount between branches).
     * The lines are read outside the branch access scope: a restricted
     * actor's view could otherwise drop another branch's lines, and since
     * every branch balances on its own the shortened replay would still pass
     * validation, silently.
     */
    private static function rollForward(self $voucher, User $actor): void
    {
        $correctedYear = $voucher->fiscalYear;
        $plAccount = Account::where('name', 'Profit & Loss')->firstOrFail();

        $originalLines = BranchContext::unrestricted(fn () => $voucher->lines()->orderBy('id')->get());

        $retargetedLines = $originalLines->map(function (JournalVoucherLine $line) use ($plAccount, $voucher) {
            $targetAccountId = $line->account->isProfitAndLoss() ? $plAccount->id : $line->account_id;

            return [
                'account_id' => $targetAccountId,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'narration' => "Roll-forward of voucher #{$voucher->seriesNumber()}",
                'branch_id' => $line->branch_id,
                'counterparty_branch_id' => $line->counterparty_branch_id,
            ];
        })->all();

        // Excludes the corrected year by id, not just by date comparison:
        // SQLite stores a `date`-cast column as a full "Y-m-d H:i:s" string,
        // which is lexicographically greater than the truncated `Y-m-d`
        // string used below, so a plain `start_date > ...` comparison
        // matches the corrected year against itself and double-posts a
        // roll-forward voucher into the very year being corrected.
        $subsequentYears = FiscalYear::whereKeyNot($correctedYear->id)
            ->where('start_date', '>', $correctedYear->start_date->toDateString())
            ->orderBy('start_date')
            ->get();

        foreach ($subsequentYears as $year) {
            static::write(
                $year,
                VoucherType::RollForwardAdjustment,
                $year->start_date->toDateString(),
                "Roll-forward adjustment for voucher #{$voucher->seriesNumber()} ({$correctedYear->name})",
                null,
                $actor,
                $retargetedLines,
                null,
                (int) $voucher->branch_id,
            );

            if ($year->status === FiscalYearStatus::Open) {
                break;
            }
        }
    }

    /**
     * The one way any module cancels a posted document's ledger effect:
     * posts a brand-new voucher mirroring every line of $original (debit and
     * credit swapped), never editing $original itself (voucher immutability).
     * Every module's cancel() calls this instead of mirroring the lines
     * itself.
     *
     * Three rules, all of them corrections of how the modules used to do this
     * by hand:
     *
     * - The reversal posts as VoucherType::Reversal, which has its own
     *   sequence. Cancelling a sale used to post its reversal as a SaleReturn
     *   and cancelling a return as a Sale, so a cancellation silently consumed
     *   a real credit-note or invoice number and the printed series gained a
     *   hole (audit P0-15). Nothing customer-facing is ever numbered by a
     *   cancellation now.
     * - The original's fiscal year must still be the open one. A reversal is
     *   dated today (or the open year's last day, once today is past it), so reversing a document from a closed - and by then very
     *   likely filed - year would move money out of a period whose VAT return
     *   is already submitted. The locked decision is that such a document is
     *   corrected with a return or credit note in the current year instead
     *   (see todo/CONTRACTS.md C4/C5).
     * - A document can only be reversed once, enforced by the row lock here
     *   and by the unique index on reversal_of_id underneath it.
     *
     * Branches: the reversal keeps the original's origin branch, and every
     * mirrored line keeps its branch and counterparty branch, so each branch
     * (and every inter-branch clearing pair) nets to zero. The original and
     * its lines are read outside the branch access scope: reading them
     * through a restricted actor's view could drop another branch's lines,
     * and because every branch balances on its own the partial reversal
     * would still validate. The actor must still be allowed to post into
     * every branch the reversal touches.
     *
     * @throws InvalidArgumentException When $original is already reversed, its fiscal year is closed, or it is itself a reversal, closing entry or roll-forward adjustment (unless $allowSystemVoucher).
     * @throws AuthorizationException When $actor may not post into one of the original's branches.
     */
    public static function reverse(self $original, User $actor, string $narration, bool $allowSystemVoucher = false): self
    {
        return DB::transaction(function () use ($original, $actor, $narration, $allowSystemVoucher) {
            $locked = BranchContext::unrestricted(fn () => static::query()->whereKey($original->getKey())->lockForUpdate()->firstOrFail());

            // A reversal, a year-end closing entry and a roll-forward
            // adjustment are the books' own bookkeeping, never a document
            // someone cancels (flags G-19). Only internal year-close or
            // reopen code may undo one, and it has to say so.
            if (! $allowSystemVoucher && in_array($locked->voucher_type, [VoucherType::Reversal, VoucherType::ClosingEntry, VoucherType::RollForwardAdjustment], true)) {
                throw new InvalidArgumentException('A reversal, closing entry or roll-forward adjustment cannot be reversed.');
            }

            if (BranchContext::unrestricted(fn () => static::query()->where('reversal_of_id', $locked->id)->exists())) {
                throw new InvalidArgumentException('This document has already been reversed.');
            }

            $fiscalYear = FiscalYear::query()->whereKey($locked->fiscal_year_id)->lockForUpdate()->firstOrFail();

            if ($fiscalYear->status !== FiscalYearStatus::Open) {
                throw new InvalidArgumentException(
                    "This document belongs to closed fiscal year {$fiscalYear->name}. Record a return or credit note in the current year instead."
                );
            }

            $originalLines = BranchContext::unrestricted(fn () => $locked->lines()->orderBy('id')->get());

            $mirroredLines = $originalLines->map(fn (JournalVoucherLine $line) => [
                'account_id' => $line->account_id,
                'debit' => $line->credit,
                'credit' => $line->debit,
                'narration' => $line->narration,
                'branch_id' => $line->branch_id,
                'counterparty_branch_id' => $line->counterparty_branch_id,
            ])->all();

            $branchId = (int) $locked->branch_id;

            static::assertActorMayPostTo($actor, [$branchId, ...static::lineBranchIds($mirroredLines, $branchId)]);

            // Dated today, but never after the open year's last day: between
            // that day and the year actually being closed, "today" belongs to
            // no open year and the reversal would fail its own date guard, so
            // no document could be cancelled at all (flags G-06, decision D1).
            $today = static::today();
            $endDate = $fiscalYear->end_date->toDateString();
            $reversalDate = min($today, $endDate);

            if ($reversalDate !== $today) {
                ReversalNotice::dated($reversalDate);
            }

            $reversal = static::write(
                $fiscalYear,
                VoucherType::Reversal,
                $reversalDate,
                $narration,
                null,
                $actor,
                $mirroredLines,
                null,
                $branchId,
            );

            $reversal->update(['reversal_of_id' => $locked->id]);
            $locked->update(['status' => 'cancelled']);

            return $reversal;
        });
    }

    /**
     * The one entry point for the five plain cash/bank voucher types
     * (CONTRACTS/T14): Cash Receipt, Cash Payment, Bank Receipt, Bank
     * Payment and Contra. Unlike Sale/Purchase/Receipt/Payment there is no
     * separate owning model - the voucher this posts IS the record, so it
     * lists and cancels exactly like a manually posted Journal voucher (see
     * VoucherType::manuallyCancellableTypes() and cancel() below).
     *
     * The cash/bank leg is computed here, never typed by the user, so it can
     * never disagree with the "other" lines: for a receipt the user names
     * where the money came from (one or more accounts) and an amount each;
     * for a payment, where it went; for a contra, a single amount moves from
     * one cash/bank account straight to another. Every amount is Money,
     * exactly summed, and the whole thing is handed to post() unchanged, so
     * it gets the same date-in-year guard, closed-year override and gapless
     * numbering as every other voucher.
     *
     * @param  array{voucher_type: string, date: string, narration: string, fiscal_year_id?: int, reason?: string, branch_id?: int|null, bank_account_id?: int, from_account_id?: int, to_account_id?: int, amount?: string|float, lines?: array<int, array{account_id: int, amount: string|float, narration?: string|null}>}  $data
     */
    public static function postCashBank(array $data, User $actor): self
    {
        $type = VoucherType::from($data['voucher_type']);

        if (! in_array($type, VoucherType::cashBankTypes(), true)) {
            throw new InvalidArgumentException("{$type->value} is not a cash/bank voucher type.");
        }

        $lines = $type === VoucherType::Contra
            ? static::contraLines($data)
            : static::cashOrBankLines($type, $data);

        return static::post(
            [
                'voucher_type' => $type->value,
                'fiscal_year_id' => $data['fiscal_year_id'] ?? null,
                'reason' => $data['reason'] ?? null,
                'date' => $data['date'],
                'narration' => $data['narration'],
                'branch_id' => $data['branch_id'] ?? null,
            ],
            $lines,
            $actor,
        );
    }

    /**
     * A contra voucher moves a single amount from one cash/bank account
     * straight to another (a cash deposit into the bank, a transfer between
     * two banks) - there is no "other account" list, just the two legs.
     *
     * @param  array{from_account_id?: int, to_account_id?: int, amount?: string|float}  $data
     * @return list<array{account_id: int, debit: string, credit: string, narration: string}>
     */
    private static function contraLines(array $data): array
    {
        $amount = Money::of($data['amount'] ?? '0');

        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('The contra amount must be greater than zero.');
        }

        $fromAccountId = (int) ($data['from_account_id'] ?? throw new InvalidArgumentException('The account money is moving from is required.'));
        $toAccountId = (int) ($data['to_account_id'] ?? throw new InvalidArgumentException('The account money is moving to is required.'));

        if ($fromAccountId === $toAccountId) {
            throw new InvalidArgumentException('The from and to accounts must be different.');
        }

        return [
            ['account_id' => $toAccountId, 'debit' => $amount->toString(), 'credit' => '0.00', 'narration' => 'Transfer in'],
            ['account_id' => $fromAccountId, 'debit' => '0.00', 'credit' => $amount->toString(), 'narration' => 'Transfer out'],
        ];
    }

    /**
     * Cash/Bank Receipt or Payment: the fixed leg is Cash-In-Hand (AS1) for
     * the two Cash types, or the chosen `bank_account_id` for the two Bank
     * types; every "other account" line is summed to get its amount, and the
     * fixed leg is created for that same total, on the opposite side for a
     * receipt versus a payment - guaranteeing an exact balance without the
     * user ever entering the cash/bank amount by hand.
     *
     * @param  array{bank_account_id?: int, lines?: array<int, array{account_id: int, amount: string|float, narration?: string|null}>}  $data
     * @return list<array{account_id: int, debit: string, credit: string, narration: string|null}>
     */
    private static function cashOrBankLines(VoucherType $type, array $data): array
    {
        $otherLines = $data['lines'] ?? [];

        if ($otherLines === []) {
            throw new InvalidArgumentException('At least one account line is required.');
        }

        $isBank = in_array($type, [VoucherType::BankReceipt, VoucherType::BankPayment], true);
        $isReceipt = in_array($type, [VoucherType::CashReceipt, VoucherType::BankReceipt], true);

        $cashOrBankAccountId = $isBank
            ? (int) ($data['bank_account_id'] ?? throw new InvalidArgumentException('A bank account is required.'))
            : Account::where('code', 'AS1')->firstOrFail()->id;

        $prepared = [];
        $total = Money::zero();

        foreach ($otherLines as $line) {
            $amount = Money::of($line['amount']);

            if (! $amount->isPositive()) {
                throw new InvalidArgumentException('Each account amount must be greater than zero.');
            }

            $total = $total->plus($amount);

            $prepared[] = [
                'account_id' => (int) $line['account_id'],
                'debit' => $isReceipt ? '0.00' : $amount->toString(),
                'credit' => $isReceipt ? $amount->toString() : '0.00',
                'narration' => $line['narration'] ?? null,
            ];
        }

        $fixedLeg = [
            'account_id' => $cashOrBankAccountId,
            'debit' => $isReceipt ? $total->toString() : '0.00',
            'credit' => $isReceipt ? '0.00' : $total->toString(),
            'narration' => $isReceipt ? 'Amount received' : 'Amount paid',
        ];

        return [$fixedLeg, ...$prepared];
    }

    /**
     * Today's date in Nepal.
     *
     * config('app.timezone') is Asia/Kathmandu, so now() already answers this,
     * but the conversion is written out because the whole point is the
     * calendar day a Nepali user is living in: an APP_TIMEZONE override in a
     * deployment's .env must not be able to date a reversal a day early (UTC
     * is 5h45 behind, so every posting between midnight and 05:45 local was
     * stamped with yesterday - audit P1, timezone).
     */
    private static function today(): string
    {
        return now()->setTimezone('Asia/Kathmandu')->toDateString();
    }

    /**
     * Cancels a manually posted journal voucher: re-reads and locks the row,
     * re-checks every blocker inside the transaction (a status checked before
     * the transaction let a double click cancel twice - audit P0-16), then
     * hands the actual ledger work to reverse(). $reason is required, matching
     * every other cancel-with-reversal method in this app.
     *
     * Two guards beyond "already cancelled" that have no equivalent on the
     * simpler dr/cr-pair vouchers (Payment/Receipt/etc.):
     *
     * - Restricted to voucher_type Journal. Every other VoucherType
     *   (Sale, Purchase, Receipt, Payment, OpeningBalance, ClosingEntry,
     *   RollForwardAdjustment, ...) is posted by another model's own
     *   post() method or by FiscalYear's own bookkeeping, and this
     *   generic Index page (JournalVoucherController::index()) lists
     *   every voucher_type with no filter - so without this guard, an
     *   admin could "cancel" e.g. a Sale's underlying voucher from here
     *   without the Sale itself ever being marked cancelled, corrupting
     *   the two records' agreement. A manually-posted Journal voucher is
     *   the only type with no dedicated owning record to cancel instead.
     * - sourceRecordLabel(): defensive backstop for the same concern,
     *   checked independently of voucher_type in case a future change
     *   ever has some other model attach itself to a Journal-typed
     *   voucher. Confirmed via the codebase's journal_voucher_id/
     *   refund_journal_voucher_id/disposal_journal_voucher_id columns:
     *   Sale, Purchase, SalesReturn, PurchaseReturn, Payment, Receipt,
     *   CapitalSale, CapitalPurchase, FixedAsset and
     *   FixedAssetDepreciation can all own a JournalVoucher; StockAdjustment/
     *   StockConversion/Quotation never do (periodic, not perpetual,
     *   inventory accounting - see Sale's class docblock). A StockTransfer
     *   between branches (BR18) does: its dispatch, receipt and cancellation
     *   vouchers (journal_voucher_id, receipt_journal_voucher_id,
     *   reversal_journal_voucher_id), mapped below since BR19.
     */
    public function cancel(User $actor, string $reason): void
    {
        DB::transaction(function () use ($actor, $reason) {
            $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'cancelled') {
                throw new InvalidArgumentException('This journal voucher has already been cancelled.');
            }

            if (! in_array($locked->voucher_type, VoucherType::manuallyCancellableTypes(), true)) {
                throw new InvalidArgumentException("Only a manually posted journal or cash/bank voucher can be cancelled here; this voucher's type ({$locked->voucher_type->value}) is posted by another module and must be cancelled from its own record.");
            }

            if ($sourceLabel = $locked->sourceRecordLabel()) {
                throw new InvalidArgumentException("This journal voucher was generated by {$sourceLabel} and must be cancelled from that record instead.");
            }

            static::reverse($locked, $actor, "Cancellation of journal voucher #{$locked->seriesNumber()}: {$reason}");
        });

        $this->refresh();
    }

    /**
     * Human label for the first record found that this voucher was posted
     * on behalf of, or null if this voucher is a standalone manual entry
     * with nothing depending on it. See cancel()'s docblock for why this
     * check exists and the full list of owning models it covers.
     *
     * Public (not just used internally by cancel()) so a report can tell a
     * standalone Journal/cash-bank voucher apart from one that belongs to a
     * module record without duplicating this lookup table - see the
     * Cancelled Documents report in AccountingReportController, which must
     * list a cancelled manual/cash-bank voucher exactly once and never
     * double-count it against the module record it might otherwise be
     * confused with.
     */
    public function sourceRecordLabel(): ?string
    {
        $sources = [
            [Sale::class, 'journal_voucher_id', 'a Sale'],
            [Purchase::class, 'journal_voucher_id', 'a Purchase'],
            [SalesReturn::class, 'journal_voucher_id', 'a Sales Return'],
            [SalesReturn::class, 'refund_journal_voucher_id', 'a Sales Return refund'],
            [PurchaseReturn::class, 'journal_voucher_id', 'a Purchase Return'],
            [PurchaseReturn::class, 'refund_journal_voucher_id', 'a Purchase Return refund'],
            [Payment::class, 'journal_voucher_id', 'a Payment'],
            [Receipt::class, 'journal_voucher_id', 'a Receipt'],
            [CapitalSale::class, 'journal_voucher_id', 'a Capital Sale'],
            [CapitalPurchase::class, 'journal_voucher_id', 'a Capital Purchase'],
            [FixedAsset::class, 'journal_voucher_id', 'a Fixed Asset purchase'],
            [FixedAsset::class, 'disposal_journal_voucher_id', 'a Fixed Asset disposal'],
            [FixedAssetDepreciation::class, 'journal_voucher_id', 'a Fixed Asset depreciation run'],
            [PayrollRun::class, 'journal_voucher_id', 'a Payroll run'],
            [PayrollPayment::class, 'journal_voucher_id', 'a Salary payment'],
            [StaffAdvance::class, 'journal_voucher_id', 'a Staff advance'],
            // A transfer between branches (BR18) owns up to three vouchers.
            [StockTransfer::class, 'journal_voucher_id', 'a Stock transfer dispatch'],
            [StockTransfer::class, 'receipt_journal_voucher_id', 'a Stock transfer receipt'],
            [StockTransfer::class, 'reversal_journal_voucher_id', 'a Stock transfer cancellation'],
        ];

        // Read unscoped: the owning record may sit in a branch the viewer
        // cannot see (a cross-branch receipt), and it must still count.
        return BranchContext::unrestricted(function () use ($sources): ?string {
            foreach ($sources as [$model, $column, $label]) {
                if ($model::where($column, $this->id)->exists()) {
                    return $label;
                }
            }

            return null;
        });
    }
}
