<?php

namespace App\Models;

use App\Enums\VoucherType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One gapless counter per (fiscal year, voucher type). JournalVoucher::
 * nextVoucherNumber() is the only place that advances it during normal
 * posting; setStartingNumber() below is the one administrative exception.
 */
#[Fillable(['fiscal_year_id', 'voucher_type', 'last_number'])]
class VoucherSequence extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'voucher_type' => VoucherType::class,
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
     * The next number this series will hand out in $fiscalYear, whether or not
     * a sequence row exists yet (a series nobody has posted into starts at 1).
     */
    public static function nextNumberFor(FiscalYear $fiscalYear, VoucherType $type): int
    {
        $lastNumber = static::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('voucher_type', $type)
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
     */
    public static function setStartingNumber(FiscalYear $fiscalYear, VoucherType $type, int $nextNumber): void
    {
        if ($nextNumber < 1) {
            throw new InvalidArgumentException('A starting number must be 1 or greater.');
        }

        DB::transaction(function () use ($fiscalYear, $type, $nextNumber) {
            $alreadyPosted = JournalVoucher::query()
                ->where('fiscal_year_id', $fiscalYear->id)
                ->where('voucher_type', $type)
                ->lockForUpdate()
                ->exists();

            if ($alreadyPosted) {
                throw new InvalidArgumentException(
                    "A document of this type has already been numbered in \"{$fiscalYear->name}\", so its starting number can no longer be changed."
                );
            }

            static::updateOrCreate(
                ['fiscal_year_id' => $fiscalYear->id, 'voucher_type' => $type],
                ['last_number' => $nextNumber - 1],
            );
        });
    }
}
