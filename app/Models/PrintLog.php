<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

/**
 * One row per print of one document (contract C9).
 *
 * Nepali IRD practice treats the first print of an invoice or a credit/debit
 * note as the original and every later print as a copy that must be stamped
 * "Copy of Original" on the face of the paper. This table is what lets the
 * PDF know which one it is, and it doubles as the audit trail behind the
 * Print Log report: who reprinted which bill, and when.
 *
 * Append-only. Nothing in this app updates or deletes a row here, which is
 * why there are no Eloquent timestamps - `printed_at` is the only time that
 * means anything.
 */
#[Fillable(['printable_type', 'printable_id', 'copy_number', 'printed_by', 'printed_at'])]
class PrintLog extends Model
{
    /**
     * The table has `printed_at` instead of created_at/updated_at, so
     * Eloquent's own timestamp handling has to be switched off entirely.
     */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'printable_id' => 'integer',
            'copy_number' => 'integer',
            'printed_by' => 'integer',
            'printed_at' => 'datetime',
        ];
    }

    /**
     * Records one print of $document by $user and returns its copy number,
     * where 1 is the original and every number above that prints as
     * "Copy of Original - {n - 1}".
     *
     * The read and the insert sit in one transaction with `lockForUpdate()`
     * on this document's existing rows, so two cashiers hitting print at the
     * same moment queue instead of both reading the same maximum. On a
     * document that has never been printed there is no row to lock (SQLite in
     * particular has no gap locking at all), so the unique index
     * `print_logs_document_copy_unique` is the backstop: the loser of that
     * race fails on the constraint rather than silently issuing a second
     * "Original".
     *
     * Safe to call inside a caller's own transaction - Laravel turns the
     * nested call into a savepoint.
     */
    public static function record(Model $document, User $user): int
    {
        return DB::transaction(function () use ($document, $user): int {
            $highestCopy = static::query()
                ->where('printable_type', $document->getMorphClass())
                ->where('printable_id', $document->getKey())
                ->lockForUpdate()
                ->max('copy_number');

            $copyNumber = ((int) $highestCopy) + 1;

            static::query()->create([
                'printable_type' => $document->getMorphClass(),
                'printable_id' => $document->getKey(),
                'copy_number' => $copyNumber,
                'printed_by' => $user->getKey(),
                'printed_at' => now(),
            ]);

            return $copyNumber;
        });
    }

    /**
     * How many times $document has been printed so far. Used by screens that
     * want to warn before a reprint; `record()` does not depend on it.
     */
    public static function copiesPrinted(Model $document): int
    {
        return (int) static::query()
            ->where('printable_type', $document->getMorphClass())
            ->where('printable_id', $document->getKey())
            ->max('copy_number');
    }

    /**
     * The document that was printed.
     *
     * @return MorphTo<Model, $this>
     */
    public function printable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The user who asked for the print. Null once that user is deleted.
     *
     * @return BelongsTo<User, $this>
     */
    public function printedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }
}
