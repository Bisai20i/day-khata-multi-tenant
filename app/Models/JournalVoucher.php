<?php

namespace App\Models;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Support\ClosedFiscalYearGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

#[Fillable(['fiscal_year_id', 'voucher_type', 'voucher_number', 'date', 'narration', 'reason', 'status', 'created_by'])]
class JournalVoucher extends Model
{
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
     * The one user-facing entry point for posting a journal voucher.
     * Resolves the target fiscal year (defaults to the currently open one),
     * gates posting into a closed year behind ClosedFiscalYearGuard (only
     * postable once a closed year has been deliberately reopened for
     * correction - see FiscalYear::isOpenForCorrection() - and even then
     * only by an admin, with a reason), and rolls a closed-year
     * correction's effect forward through any already-created subsequent
     * fiscal years.
     *
     * @param  array{voucher_type?: string, date: string, narration: string, reason?: string, fiscal_year_id?: int}  $header
     * @param  array<int, array{account_id: int, debit?: float|string, credit?: float|string, narration?: string}>  $lines
     */
    public static function post(array $header, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($header, $lines, $actor) {
            $fiscalYear = isset($header['fiscal_year_id'])
                ? FiscalYear::findOrFail($header['fiscal_year_id'])
                : FiscalYear::current();

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
                ClosedFiscalYearGuard::logCorrection($fiscalYear, $reason, "Journal voucher #{$voucher->voucher_number}: {$header['narration']}");
            }

            return $voucher;
        });
    }

    /**
     * Shared low-level writer: validates double-entry shape/balance,
     * atomically claims the next voucher number, and creates the header +
     * lines. Used directly (bypassing post()'s fiscal-year resolution and
     * closed-year gate) by FiscalYear::close() and the roll-forward
     * cascade, both of which target a specific fiscal year for
     * system-generated bookkeeping rather than a user-initiated posting.
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
        static::validateLines($lines);

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
     * @param  array<int, array{account_id: int, debit?: float|string, credit?: float|string, narration?: string}>  $lines
     */
    private static function validateLines(array $lines): void
    {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('A journal voucher needs at least two lines.');
        }

        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($lines as $line) {
            $debit = (float) ($line['debit'] ?? 0);
            $credit = (float) ($line['credit'] ?? 0);

            if (($debit > 0) === ($credit > 0)) {
                throw new InvalidArgumentException('Each line must have exactly one of debit or credit greater than zero.');
            }

            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        if (round($totalDebit, 2) !== round($totalCredit, 2)) {
            throw new InvalidArgumentException('Total debit must equal total credit.');
        }
    }

    private static function nextVoucherNumber(FiscalYear $fiscalYear, VoucherType $type): int
    {
        $sequence = VoucherSequence::firstOrCreate(
            ['fiscal_year_id' => $fiscalYear->id, 'voucher_type' => $type],
            ['last_number' => 0],
        );

        $sequence = VoucherSequence::whereKey($sequence->id)->lockForUpdate()->first();
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
     * Reverses this journal voucher: posts a brand-new voucher mirroring
     * every line of the original (debit/credit swapped) via post() itself
     * - so it lands in the currently open fiscal year (or a reopened
     * closed year, if one is targeted explicitly some day) exactly like
     * every other module's cancel() does - never edits the original
     * (voucher immutability rule; matches Payment::cancel(),
     * Receipt::cancel(), Sale::cancel(), etc. exactly). $reason is
     * required, matching every other cancel-with-reversal method in this
     * app.
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
        if ($this->status === 'cancelled') {
            throw new InvalidArgumentException('This journal voucher has already been cancelled.');
        }

        if ($this->voucher_type !== VoucherType::Journal) {
            throw new InvalidArgumentException("Only a manually posted journal voucher can be cancelled here; this voucher's type ({$this->voucher_type->value}) is posted by another module and must be cancelled from its own record.");
        }

        if ($sourceLabel = $this->sourceRecordLabel()) {
            throw new InvalidArgumentException("This journal voucher was generated by {$sourceLabel} and must be cancelled from that record instead.");
        }

        DB::transaction(function () use ($actor, $reason) {
            $mirroredLines = $this->lines()->get()->map(fn (JournalVoucherLine $line) => [
                'account_id' => $line->account_id,
                'debit' => (float) $line->credit,
                'credit' => (float) $line->debit,
                'narration' => $line->narration,
            ])->all();

            static::post(
                [
                    'voucher_type' => VoucherType::Journal->value,
                    'date' => now()->toDateString(),
                    'narration' => "Cancellation of journal voucher #{$this->voucher_number}: {$reason}",
                ],
                $mirroredLines,
                $actor,
            );

            $this->update(['status' => 'cancelled']);
        });
    }

    /**
     * Human label for the first record found that this voucher was posted
     * on behalf of, or null if this voucher is a standalone manual entry
     * with nothing depending on it. See cancel()'s docblock for why this
     * check exists and the full list of owning models it covers.
     */
    private function sourceRecordLabel(): ?string
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
