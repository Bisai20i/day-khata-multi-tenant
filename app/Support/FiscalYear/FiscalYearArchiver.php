<?php

namespace App\Support\FiscalYear;

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\FiscalYearArchive;
use App\Models\JournalVoucher;
use App\Models\User;
use App\Support\Branches\BranchContext;
use App\Support\Money\Money;
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
            // The series code printed with the number in a per_branch year
            // (plan 12, BR20: 12 repeats once per branch), null in a shared
            // year. Archive files written before BR20 have no such column;
            // ArchivedFiscalYearController checks for it.
            $table->string('series_code')->nullable();
            // The voucher's origin branch (plan 12, section 5.3). Together
            // with the lines' branch_id this lets ArchivedFiscalYearController
            // show a branch-restricted user only the vouchers the live
            // JournalVoucher scope would (section 5.7). Archive files written
            // before this column existed have none, and the controller refuses
            // them to restricted users rather than show every branch.
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->date('date');
            $table->string('narration');
            $table->string('reason')->nullable();
            $table->string('created_by_name');
            $table->timestamp('created_at')->nullable();
        });

        Schema::connection($connectionName)->create('journal_voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_voucher_id');
            // Nullable, because plenty of real accounts have no code at all:
            // App\Models\Concerns\HasLedgerAccount creates a customer's,
            // supplier's, agent's and fixed asset's own ledger account with
            // only a name. A NOT NULL column here made archiving throw for
            // any year that contained a single party posting, which in
            // practice is every year a tenant has actually traded in (audit
            // P1, "Fiscal-year archiving throws for any year with party
            // postings").
            $table->string('account_code')->nullable();
            $table->string('account_name');
            $table->decimal('debit', 20, 2)->default(0);
            $table->decimal('credit', 20, 2)->default(0);
            $table->string('narration')->nullable();
            // The line's own branch, and on Inter-Branch Clearing lines the
            // branch on the other side (plan 12, section 5.3). Ids only, like
            // the live columns; nullable for the same pre-branch reason as
            // journal_vouchers.branch_id.
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('counterparty_branch_id')->nullable();

            $table->index('journal_voucher_id');
            $table->index('branch_id');
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
     * Read outside the branch access scope (plan 12, section 5.7): the
     * archive is the year's complete record, so a restricted actor's view
     * must never decide which vouchers or lines it holds.
     *
     * @return array{0: int, 1: int} [voucherCount, lineCount]
     */
    private static function copyData(FiscalYear $fiscalYear, string $connectionName): array
    {
        $voucherCount = 0;
        $lineCount = 0;

        BranchContext::unrestricted(function () use ($fiscalYear, $connectionName, &$voucherCount, &$lineCount) {
            JournalVoucher::where('fiscal_year_id', $fiscalYear->id)
                ->with(['lines.account', 'creator'])
                ->chunkById(200, function ($vouchers) use ($connectionName, &$voucherCount, &$lineCount) {
                    // One query for the chunk's branch-series codes (none in a
                    // shared year).
                    JournalVoucher::loadSeriesBranches($vouchers);

                    foreach ($vouchers as $voucher) {
                        DB::connection($connectionName)->table('journal_vouchers')->insert([
                            'id' => $voucher->id,
                            'voucher_type' => $voucher->voucher_type->value,
                            'voucher_number' => $voucher->voucher_number,
                            'series_code' => $voucher->seriesCode(),
                            'branch_id' => $voucher->branch_id,
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
                            // Both nullable-safe: an account with no code is
                            // normal (party and fixed-asset accounts), and a line
                            // whose account row has since been deleted still
                            // deserves to be archived rather than to abort the
                            // whole year's copy.
                            'account_code' => $line->account?->code,
                            'account_name' => $line->account?->name ?? 'Unknown',
                            'debit' => Money::of($line->debit)->toString(),
                            'credit' => Money::of($line->credit)->toString(),
                            'narration' => $line->narration,
                            'branch_id' => $line->branch_id,
                            'counterparty_branch_id' => $line->counterparty_branch_id,
                        ])->all();

                        if ($lineRows !== []) {
                            DB::connection($connectionName)->table('journal_voucher_lines')->insert($lineRows);
                            $lineCount += count($lineRows);
                        }
                    }
                });
        });

        return [$voucherCount, $lineCount];
    }
}
