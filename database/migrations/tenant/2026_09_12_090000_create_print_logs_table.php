<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only record of every time a document was printed, written by
     * `PrintLog::record()` from each module's `print()` action (contract C9).
     *
     * Nepali IRD practice is that only the first print of an invoice or note
     * is the original; every later print has to be stamped "Copy of Original"
     * and be traceable to whoever asked for it. That makes this table part of
     * the audit trail, not a convenience log: rows are never updated and never
     * deleted, so there is no updated_at.
     *
     * The unique index on (printable_type, printable_id, copy_number) is the
     * real guarantee that two simultaneous prints can never both be handed
     * copy 2 - `PrintLog::record()` takes a row lock first, but on a fresh
     * document there are no rows to lock, so the constraint is what makes the
     * race safe.
     */
    public function up(): void
    {
        Schema::create('print_logs', function (Blueprint $table) {
            $table->id();
            $table->string('printable_type');
            $table->unsignedBigInteger('printable_id');
            $table->unsignedInteger('copy_number');
            // Nullable so deleting a user never destroys the print history;
            // the row keeps saying a print happened, just not by whom.
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('printed_at')->useCurrent();

            $table->index(['printable_type', 'printable_id']);
            $table->index('printed_at');
            $table->unique(['printable_type', 'printable_id', 'copy_number'], 'print_logs_document_copy_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_logs');
    }
};
