<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['journal_voucher_id', 'account_id', 'debit', 'credit', 'narration'])]
class JournalVoucherLine extends Model
{
    /**
     * Decimal, not `decimal:2`: `decimal:2` let a value with more than two
     * decimals through to the column and left MySQL to round it per line, so
     * three Dr lines of 333.333 against one Cr line of 999.999 passed the
     * balance check and then stored Dr 999.99 against Cr 1000.00 (audit P0-2).
     * Decimal throws on that write instead. JournalVoucher::validateLines()
     * already normalises every amount before it gets here, so this cast is the
     * backstop for anything that writes a line by another route.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit' => Decimal::class.':2',
            'credit' => Decimal::class.':2',
        ];
    }

    /**
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function journalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
