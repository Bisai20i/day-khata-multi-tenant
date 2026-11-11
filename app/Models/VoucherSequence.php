<?php

namespace App\Models;

use App\Enums\VoucherType;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One gapless counter per (fiscal year, voucher type, series_branch_id).
 * series_branch_id is 0 for the shared series (every shared-scope year, and
 * the header-only ProductionEntry series in every year); in a per_branch
 * year it is the header branch the number belongs to (plan 12, BR20,
 * invariant I7). claimNext() is the only place that advances a counter
 * during normal posting; setStartingNumber() below is the one
 * administrative exception.
 */
#[Fillable(['fiscal_year_id', 'voucher_type', 'series_branch_id', 'last_number'])]
class VoucherSequence extends Model
{
    /**
     * The shared, company-wide series.
     */
    public const SHARED_SERIES = 0;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'voucher_type' => VoucherType::class,
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
     * Claims the next number in the ($fiscalYear, $type, $seriesBranchId)
     * series. Shared by JournalVoucher::write() and ProductionEntry, so the
     * two can never drift apart.
     *
     * firstOrCreate() used to race here: two simultaneous first postings of a
     * type both saw no row, both inserted, and the loser hit the unique index
     * with a 500 instead of getting a number. insertOrIgnore() makes the
     * create side idempotent (the unique key is (fiscal_year_id,
     * voucher_type, series_branch_id)), and the row is then re-read under
     * lockForUpdate() so the increment itself is serialised and the series
     * stays gapless (a no-op on SQLite, which is why this is verified by
     * review rather than by a test). Must run inside the caller's
     * transaction, so a rolled-back posting gives its number back.
     */
    public static function claimNext(FiscalYear $fiscalYear, VoucherType $type, int $seriesBranchId = self::SHARED_SERIES): int
    {
        // Through the model's own query builder, so it uses the tenant
        // connection and table. A raw insert bypasses Eloquent's timestamps,
        // hence the explicit pair.
        static::query()->insertOrIgnore([
            'fiscal_year_id' => $fiscalYear->id,
            'voucher_type' => $type->value,
            'series_branch_id' => $seriesBranchId,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = static::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('voucher_type', $type)
            ->where('series_branch_id', $seriesBranchId)
            ->lockForUpdate()
            ->firstOrFail();

        $sequence->increment('last_number');

        return (int) $sequence->last_number;
    }

    /**
     * The next number this series will hand out in $fiscalYear, whether or not
     * a sequence row exists yet (a series nobody has posted into starts at 1).
     */
    public static function nextNumberFor(FiscalYear $fiscalYear, VoucherType $type, int $seriesBranchId = self::SHARED_SERIES): int
    {
        $lastNumber = static::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('voucher_type', $type)
            ->where('series_branch_id', $seriesBranchId)
            ->value('last_number');

        return (int) $lastNumber + 1;
    }

    /**
     * Admin setting for a tenant migrating mid-year from another system: the
     * next voucher of $type posted into $fiscalYear gets exactly $nextNumber,
     * so their invoice numbering continues where the old books left off
     * instead of restarting at 1.
     *
     * Allowed only while that series is still untouched in that year. Once a
     * document has been numbered, moving the counter would either duplicate a
     * printed number or leave a hole in a legally gapless series, which is the
     * exact failure this whole numbering rework exists to prevent - so the
     * setting refuses rather than "fixing" it. Checked against the vouchers
     * themselves, not the counter, because a sequence row can exist at 0 while
     * nothing has been posted.
     *
     * Shared-scope years only (plan 12, BR20): a per_branch year has one
     * series per branch, and a single starting number cannot say which one
     * it is for. Kept out of scope on purpose; the year's scope cannot be
     * per_branch while a starting number is set either (NumberingScope).
     */
    public static function setStartingNumber(FiscalYear $fiscalYear, VoucherType $type, int $nextNumber): void
    {
        if ($nextNumber < 1) {
            throw new InvalidArgumentException('A starting number must be 1 or greater.');
        }

        DB::transaction(function () use ($fiscalYear, $type, $nextNumber) {
            // Locked so a concurrent scope change (which takes the same lock)
            // cannot switch the year to per-branch numbering under this.
            $lockedYear = FiscalYear::query()->whereKey($fiscalYear->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedYear->usesPerBranchNumbering()) {
                $label = strtolower(ActiveBranchSelection::label());

                throw new InvalidArgumentException(
                    "\"{$lockedYear->name}\" numbers documents separately per {$label}, so a starting number cannot be set for it."
                );
            }

            // Unrestricted: the series is company-wide, so a voucher filed in a
            // branch the admin cannot see still counts (plan 12, section 5.7).
            // A plain read under the fiscal-year lock above, which every
            // poster takes first; locking voucher rows here would invert the
            // voucher -> fiscal year order reverse() uses.
            $alreadyPosted = BranchContext::unrestricted(fn (): bool => JournalVoucher::query()
                ->where('fiscal_year_id', $fiscalYear->id)
                ->where('voucher_type', $type)
                ->exists());

            if ($alreadyPosted) {
                throw new InvalidArgumentException(
                    "A document of this type has already been numbered in \"{$fiscalYear->name}\", so its starting number can no longer be changed."
                );
            }

            static::updateOrCreate(
                ['fiscal_year_id' => $fiscalYear->id, 'voucher_type' => $type, 'series_branch_id' => self::SHARED_SERIES],
                ['last_number' => $nextNumber - 1],
            );
        });
    }
}
