<?php

namespace App\Models;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Support\ClosedFiscalYearGuard;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

#[Fillable(['fiscal_year_id', 'voucher_type', 'voucher_number', 'date', 'narration', 'reason', 'status', 'created_by', 'reversal_of_id'])]
class JournalVoucher extends Model
{
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
            'date' => 'date',
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
     * The one user-facing entry point for posting a journal voucher.
     * Resolves the target fiscal year (defaults to the currently open one),
     * gates posting into a closed year behind ClosedFiscalYearGuard (only
     * postable once a closed year has been deliberately reopened for
     * correction - see FiscalYear::isOpenForCorrection() - and even then
     * only by an admin, with a reason), and rolls a closed-year
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
     * @param  array{voucher_type?: string, date: string, narration: string, reason?: string, fiscal_year_id?: int}  $header
     * @param  array<int, array{account_id: int, debit?: float|string, credit?: float|string, narration?: string}>  $lines
     */
    public static function post(array $header, array $lines, User $actor, bool $logCorrection = true): self
    {
        return DB::transaction(function () use ($header, $lines, $actor, $logCorrection) {
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
            // year, admin+reason or not. See ClosedFiscalYearGuard's
            // docblock for the locked design decision this implements.
            ClosedFiscalYearGuard::ensurePostable($fiscalYear, $reason);

            if ($isOverride && $actor->role?->slug !== 'admin') {
                throw new AuthorizationException('Only an admin may post into a reopened fiscal year.');
            }

            $voucher = static::write(
                $fiscalYear,
                VoucherType::from($header['voucher_type'] ?? VoucherType::Journal->value),
                $header['date'],
                $header['narration'],
                $reason,
                $actor,
                $lines,
            );

            if ($isOverride) {
                static::rollForward($voucher, $actor);

                if ($logCorrection) {
                    ClosedFiscalYearGuard::logCorrection($fiscalYear, $reason, "Journal voucher #{$voucher->voucher_number}: {$header['narration']}");
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
     * @param  array<int, array{account_id: int, debit?: float|string, credit?: float|string, narration?: string}>  $lines
     */
    public static function write(
        FiscalYear $fiscalYear,
        VoucherType $type,
        string $date,
        string $narration,
        ?string $reason,
        User $actor,
        array $lines,
    ): self {
        $date = static::assertDateInsideFiscalYear($fiscalYear, $date);
        $lines = static::validateLines($lines);

        $voucher = static::create([
            'fiscal_year_id' => $fiscalYear->id,
            'voucher_type' => $type,
            'voucher_number' => static::nextVoucherNumber($fiscalYear, $type),
            'date' => $date,
            'narration' => $narration,
            'reason' => $reason,
            'status' => 'posted',
            'created_by' => $actor->id,
        ]);

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
     * @param  array<int, array{account_id: int, debit?: float|string, credit?: float|string, narration?: string}>  $lines
     * @return list<array{account_id: int, debit: string, credit: string, narration: string|null}>
     */
    private static function validateLines(array $lines): array
    {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('A journal voucher needs at least two lines.');
        }

        $normalised = [];
        $debits = [];
        $credits = [];

        foreach ($lines as $line) {
            // ofNullable so a blank box ('' or null) reads as zero; anything
            // else must parse exactly, with at most two decimals.
            $debit = Money::ofNullable($line['debit'] ?? null) ?? Money::zero();
            $credit = Money::ofNullable($line['credit'] ?? null) ?? Money::zero();

            if ($debit->isNegative() || $credit->isNegative()) {
                throw new InvalidArgumentException('A journal voucher line cannot carry a negative amount; put it on the other side instead.');
            }

            // Also rejects a zero line (neither side positive): a line that
            // moves nothing is never a real posting, and silently keeping it
            // would let a "balanced" voucher consist entirely of nothing.
            if ($debit->isPositive() === $credit->isPositive()) {
                throw new InvalidArgumentException('Each line must have exactly one of debit or credit greater than zero.');
            }

            $debits[] = $debit;
            $credits[] = $credit;

            $normalised[] = [
                'account_id' => $line['account_id'],
                'debit' => $debit->toString(),
                'credit' => $credit->toString(),
                'narration' => $line['narration'] ?? null,
            ];
        }

        if (! Money::sum($debits)->isEqualTo(Money::sum($credits))) {
            throw new InvalidArgumentException('Total debit must equal total credit.');
        }

        return $normalised;
    }

    /**
     * Claims the next number in this (fiscal year, voucher type) series.
     *
     * firstOrCreate() used to race here: two simultaneous first postings of a
     * type both saw no row, both inserted, and the loser hit the unique index
     * with a 500 instead of getting a number. insertOrIgnore() makes the
     * create side idempotent, and the row is then re-read under
     * lockForUpdate() so the increment itself is serialised (a no-op on
     * SQLite, which is why this is verified by review rather than by a test).
     */
    private static function nextVoucherNumber(FiscalYear $fiscalYear, VoucherType $type): int
    {
        // Through the model's own query builder, so it uses the tenant
        // connection and table the rest of this class does. A raw insert
        // bypasses Eloquent's timestamps, hence the explicit pair.
        VoucherSequence::query()->insertOrIgnore([
            'fiscal_year_id' => $fiscalYear->id,
            'voucher_type' => $type->value,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = VoucherSequence::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('voucher_type', $type)
            ->lockForUpdate()
            ->firstOrFail();

        $sequence->increment('last_number');

        return $sequence->last_number;
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
     */
    private static function rollForward(self $voucher, User $actor): void
    {
        $correctedYear = $voucher->fiscalYear;
        $plAccount = Account::where('name', 'Profit & Loss')->firstOrFail();

        $retargetedLines = $voucher->lines()->get()->map(function (JournalVoucherLine $line) use ($plAccount, $voucher) {
            $targetAccountId = $line->account->isProfitAndLoss() ? $plAccount->id : $line->account_id;

            return [
                'account_id' => $targetAccountId,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'narration' => "Roll-forward of voucher #{$voucher->voucher_number}",
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
                "Roll-forward adjustment for voucher #{$voucher->voucher_number} ({$correctedYear->name})",
                null,
                $actor,
                $retargetedLines,
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
     *   dated today, so reversing a document from a closed - and by then very
     *   likely filed - year would move money out of a period whose VAT return
     *   is already submitted. The locked decision is that such a document is
     *   corrected with a return or credit note in the current year instead
     *   (see todo/CONTRACTS.md C4/C5).
     * - A document can only be reversed once, enforced by the row lock here
     *   and by the unique index on reversal_of_id underneath it.
     *
     * @throws InvalidArgumentException When $original is already reversed, or its fiscal year is closed.
     */
    public static function reverse(self $original, User $actor, string $narration): self
    {
        return DB::transaction(function () use ($original, $actor, $narration) {
            $locked = static::query()->whereKey($original->getKey())->lockForUpdate()->firstOrFail();

            if (static::query()->where('reversal_of_id', $locked->id)->exists()) {
                throw new InvalidArgumentException('This document has already been reversed.');
            }

            $fiscalYear = FiscalYear::query()->whereKey($locked->fiscal_year_id)->lockForUpdate()->firstOrFail();

            if ($fiscalYear->status !== FiscalYearStatus::Open) {
                throw new InvalidArgumentException(
                    "This document belongs to closed fiscal year {$fiscalYear->name}. Record a return or credit note in the current year instead."
                );
            }

            $mirroredLines = $locked->lines()->orderBy('id')->get()->map(fn (JournalVoucherLine $line) => [
                'account_id' => $line->account_id,
                'debit' => $line->credit,
                'credit' => $line->debit,
                'narration' => $line->narration,
            ])->all();

            $reversal = static::write(
                $fiscalYear,
                VoucherType::Reversal,
                static::today(),
                $narration,
                null,
                $actor,
                $mirroredLines,
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
     * @param  array{voucher_type: string, date: string, narration: string, fiscal_year_id?: int, reason?: string, bank_account_id?: int, from_account_id?: int, to_account_id?: int, amount?: string|float, lines?: array<int, array{account_id: int, amount: string|float, narration?: string|null}>}  $data
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
     *   StockTransfer/StockConversion/Quotation never do (periodic, not
     *   perpetual, inventory accounting - see Sale's class docblock).
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

            static::reverse($locked, $actor, "Cancellation of journal voucher #{$locked->voucher_number}: {$reason}");
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
        ];

        foreach ($sources as [$model, $column, $label]) {
            if ($model::where($column, $this->id)->exists()) {
                return $label;
            }
        }

        return null;
    }
}
