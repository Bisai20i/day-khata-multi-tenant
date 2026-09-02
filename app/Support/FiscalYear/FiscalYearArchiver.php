<?php

namespace App\Support\FiscalYear;

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\FiscalYearArchive;
use App\Models\JournalVoucher;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Copies a closed fiscal year's ledger (journal_vouchers +
 * journal_voucher_lines - the sole source of truth every report in this app
 * reads from, since Sale/Purchase/StockAdjustment/etc. carry no
 * fiscal_year_id of their own and post entirely through
 * JournalVoucher::write()) out to a standalone, self-contained SQLite file:
 * this tenant's cold storage for that year. Deliberately a COPY, never a
 * move - the live journal_vouchers/journal_voucher_lines rows for the
 * archived year are left exactly as they were, so nothing this feature does
 * can ever lose data. Shrinking the live database is explicit future work,
 * not attempted here.
 *
 * This translates ../day_khata/migration_plan/01-architecture-tenancy.md
 * §3.4's design (a single hardcoded MySQL "archive" database; per-tenant
 * archives via `tenant_{id}_archive` MySQL databases; DROP/CREATE DATABASE
 * inside a queued job) to this app's actual reality, confirmed by reading
 * config/tenancy.php: every tenant database here is already its own
 * isolated SQLite file (`SQLiteDatabaseManager`), not a database living
 * inside one shared MySQL instance. There is no DROP/CREATE DATABASE here,
 * no DEFINER stability concern, and no tenant-id-into-DDL injection surface
 * to whitelist-guard the way the doc requires for MySQL - one archive is
 * simply one more isolated SQLite file, named from server-trusted values
 * only (`tenant('id')` from the already-initialized tenancy context, plus
 * this row's own autoincrement id - never from request input). This also
 * runs synchronously rather than as a queued job, deliberately diverging
 * from the doc: this app's own established convention for an admin-
 * triggered, file-producing action is synchronous (see BackupController),
 * and unlike the doc's MySQL DROP/CREATE DATABASE (a real shared-instance
 * locking/security concern), a SQLite file copy is local I/O with no such
 * risk to justify queuing.
 */
class FiscalYearArchiver
{
    private const DISK = 'local';

    /**
     * The trigger mechanism. Deliberately NOT wired into FiscalYear::close()
     * - archiving is a separate, later, manual admin action (safer: it never
     * adds risk to close()'s existing P&L sweep/balance carry-forward
     * transaction, and a year is often worth keeping live for a while after
     * closing before it's cold-storage-worthy).
     */
    public static function archive(FiscalYear $fiscalYear, User $actor): FiscalYearArchive
    {
        if ($fiscalYear->status !== FiscalYearStatus::Closed) {
            throw new InvalidArgumentException('Only a closed fiscal year can be archived.');
        }

        if (FiscalYearArchive::where('fiscal_year_id', $fiscalYear->id)->exists()) {
            throw new InvalidArgumentException('This fiscal year has already been archived.');
        }

        $disk = Storage::disk(self::DISK);
        $relativePath = self::pathFor($fiscalYear);

        $disk->makeDirectory(dirname($relativePath));

        if ($disk->exists($relativePath)) {
            $disk->delete($relativePath);
        }

        // The SQLite connector requires the database file to already exist
        // and resolve via realpath() before it will connect - it does not
        // create one on the fly, unlike some other drivers.
        $disk->put($relativePath, '');
        $absolutePath = $disk->path($relativePath);

        $connectionName = 'fiscal_year_archive_write_'.$fiscalYear->id;

        try {
            self::registerConnection($connectionName, $absolutePath);
            self::buildSchema($connectionName);

            [$voucherCount, $lineCount] = self::copyData($fiscalYear, $connectionName);

            DB::purge($connectionName);

            return FiscalYearArchive::create([
                'fiscal_year_id' => $fiscalYear->id,
                'file_path' => $relativePath,
                'voucher_count' => $voucherCount,
                'line_count' => $lineCount,
                'archived_by' => $actor->id,
                'archived_at' => now(),
            ]);
        } catch (Throwable $e) {
            DB::purge($connectionName);

            if ($disk->exists($relativePath)) {
                $disk->delete($relativePath);
            }

            throw $e;
        }
    }

    /**
     * The archived-connection-resolution helper. Registers (idempotently -
     * safe to call repeatedly within the same request/worker) a read-only
     * connection to an already-archived year's file and returns its
     * connection name, ready for `DB::connection($name)`. `PRAGMA
     * query_only` enforces read-only at the SQLite level itself, not just by
     * controller convention, so even a future bug in a caller can't
     * accidentally write into cold storage through this connection.
     */
    public static function connectionFor(FiscalYearArchive $archive): string
    {
        $connectionName = 'fiscal_year_archive_read_'.$archive->id;

        if (! array_key_exists($connectionName, DB::getConnections())) {
            $absolutePath = Storage::disk(self::DISK)->path($archive->file_path);

            if (! is_file($absolutePath)) {
                throw new RuntimeException("Archive file for fiscal year archive #{$archive->id} is missing.");
            }

            self::registerConnection($connectionName, $absolutePath);
            DB::connection($connectionName)->statement('PRAGMA query_only = ON');
        }

        return $connectionName;
    }

    /**
     * Tenant-scoped by construction: `tenant('id')` only resolves from the
     * currently-initialized tenancy context (never from request input), and
     * every request into this code path is already confined to one
     * tenant's own isolated database connection - there is no query this
     * class could run that would ever reach another tenant's archive file.
     */
    private static function pathFor(FiscalYear $fiscalYear): string
    {
        return 'fiscal-year-archives/'.tenant('id')."/fy_{$fiscalYear->id}.sqlite";
    }

    private static function registerConnection(string $name, string $absolutePath): void
    {
        config(["database.connections.{$name}" => [
            'driver' => 'sqlite',
            'database' => $absolutePath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        DB::purge($name);
    }

    private static function buildSchema(string $connectionName): void
    {
        Schema::connection($connectionName)->create('journal_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_type');
            $table->unsignedInteger('voucher_number');
            $table->date('date');
            $table->string('narration');
            $table->string('reason')->nullable();
            $table->string('created_by_name');
            $table->timestamp('created_at')->nullable();
        });

        Schema::connection($connectionName)->create('journal_voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_voucher_id');
            $table->string('account_code');
            $table->string('account_name');
            $table->decimal('debit', 20, 2)->default(0);
            $table->decimal('credit', 20, 2)->default(0);
            $table->string('narration')->nullable();

            $table->index('journal_voucher_id');
        });
    }

    /**
     * Denormalizes account code/name and the creator's name directly onto
     * the archived rows (rather than foreign-keying to anything) so the
     * archive file is fully self-contained and stays queryable forever, even
     * if the live tenant database later renumbers accounts or deletes a
     * user. Original journal_voucher/journal_voucher_line ids are preserved
     * verbatim so a line's `journal_voucher_id` stays a meaningful,
     * self-consistent reference within the archive file on its own.
     *
     * @return array{0: int, 1: int} [voucherCount, lineCount]
     */
    private static function copyData(FiscalYear $fiscalYear, string $connectionName): array
    {
        $voucherCount = 0;
        $lineCount = 0;

        JournalVoucher::where('fiscal_year_id', $fiscalYear->id)
            ->with(['lines.account', 'creator'])
            ->chunkById(200, function ($vouchers) use ($connectionName, &$voucherCount, &$lineCount) {
                foreach ($vouchers as $voucher) {
                    DB::connection($connectionName)->table('journal_vouchers')->insert([
                        'id' => $voucher->id,
                        'voucher_type' => $voucher->voucher_type->value,
                        'voucher_number' => $voucher->voucher_number,
                        'date' => $voucher->date->toDateString(),
                        'narration' => $voucher->narration,
                        'reason' => $voucher->reason,
                        'created_by_name' => $voucher->creator?->name ?? 'Unknown',
                        'created_at' => $voucher->created_at,
                    ]);
                    $voucherCount++;

                    $lineRows = $voucher->lines->map(fn ($line) => [
                        'id' => $line->id,
                        'journal_voucher_id' => $voucher->id,
                        'account_code' => $line->account->code,
                        'account_name' => $line->account->name,
                        'debit' => (string) $line->debit,
                        'credit' => (string) $line->credit,
                        'narration' => $line->narration,
                    ])->all();

                    if ($lineRows !== []) {
                        DB::connection($connectionName)->table('journal_voucher_lines')->insert($lineRows);
                        $lineCount += count($lineRows);
                    }
                }
            });

        return [$voucherCount, $lineCount];
    }
}
