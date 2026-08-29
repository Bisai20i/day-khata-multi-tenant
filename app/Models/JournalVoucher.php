<?php

namespace App\Models;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

#[Fillable(['fiscal_year_id', 'voucher_type', 'voucher_number', 'date', 'narration', 'reason', 'created_by'])]
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
     * gates posting into a closed year behind an admin+reason override, and
     * rolls a closed-year correction's effect forward through any
     * already-created subsequent fiscal years.
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

            if ($isOverride) {
                if (! $reason) {
                    throw new InvalidArgumentException('A reason is required to post into a closed fiscal year.');
                }

                if ($actor->role?->slug !== 'admin') {
                    throw new AuthorizationException('Only an admin may post into a closed fiscal year.');
                }
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
}
