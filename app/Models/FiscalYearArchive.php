<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per fiscal year that has been archived (see
 * App\Support\FiscalYear\FiscalYearArchiver). `file_path` points at the
 * standalone SQLite file on the "local" disk holding that year's copied
 * journal_vouchers/journal_voucher_lines - never read directly through
 * Eloquent, always through FiscalYearArchiver::connectionFor($this).
 */
#[Fillable(['fiscal_year_id', 'file_path', 'voucher_count', 'line_count', 'archived_by', 'archived_at'])]
class FiscalYearArchive extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'voucher_count' => 'integer',
            'line_count' => 'integer',
            'archived_at' => 'datetime',
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
    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }
}
