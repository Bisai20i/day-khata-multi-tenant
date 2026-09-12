<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use App\Models\FiscalYearArchive;
use App\Support\FiscalYear\FiscalYearArchiver;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only browsing of an already-archived fiscal year's cold-storage
 * ledger (see App\Support\FiscalYear\FiscalYearArchiver). Every query here
 * runs against FiscalYearArchiver::connectionFor()'s `PRAGMA query_only`
 * connection, never the live tenant database - nothing in this controller
 * writes anything.
 */
class ArchivedFiscalYearController extends Controller
{
    public function show(FiscalYearArchive $fiscalYearArchive): Response
    {
        $connection = FiscalYearArchiver::connectionFor($fiscalYearArchive);

        $vouchers = DB::connection($connection)
            ->table('journal_vouchers as v')
            ->leftJoin('journal_voucher_lines as l', 'l.journal_voucher_id', '=', 'v.id')
            ->groupBy('v.id', 'v.voucher_type', 'v.voucher_number', 'v.date', 'v.narration', 'v.reason', 'v.created_by_name')
            ->orderBy('v.date')
            ->orderBy('v.voucher_number')
            ->selectRaw('v.id, v.voucher_type, v.voucher_number, v.date, v.narration, v.reason, v.created_by_name, COALESCE(SUM(CAST(ROUND(l.debit * 100) AS INTEGER)), 0) as total_debit_scaled, COALESCE(SUM(CAST(ROUND(l.credit * 100) AS INTEGER)), 0) as total_credit_scaled')
            ->get()
            ->map(fn ($voucher) => [
                'id' => $voucher->id,
                'voucherType' => $voucher->voucher_type,
                'voucherNumber' => $voucher->voucher_number,
                'date' => $voucher->date,
                'narration' => $voucher->narration,
                'reason' => $voucher->reason,
                'createdByName' => $voucher->created_by_name,
                'totalDebit' => $this->fromScaled($voucher->total_debit_scaled)->toString(),
                'totalCredit' => $this->fromScaled($voucher->total_credit_scaled)->toString(),
            ]);

        return Inertia::render('Tenant/Accounting/FiscalYearArchive/Show', [
            'fiscalYear' => $this->fiscalYearProp($fiscalYearArchive),
            'archive' => $this->archiveProp($fiscalYearArchive),
            'vouchers' => $vouchers,
        ]);
    }

    public function voucher(FiscalYearArchive $fiscalYearArchive, int $voucherId): Response
    {
        $connection = FiscalYearArchiver::connectionFor($fiscalYearArchive);

        $voucher = DB::connection($connection)->table('journal_vouchers')->where('id', $voucherId)->first();

        if (! $voucher) {
            abort(404);
        }

        $lines = DB::connection($connection)
            ->table('journal_voucher_lines')
            ->where('journal_voucher_id', $voucherId)
            ->orderBy('id')
            ->get()
            ->map(fn ($line) => [
                'id' => $line->id,
                'accountCode' => $line->account_code,
                'accountName' => $line->account_name,
                'debit' => Money::of($line->debit)->toString(),
                'credit' => Money::of($line->credit)->toString(),
                'narration' => $line->narration,
            ]);

        return Inertia::render('Tenant/Accounting/FiscalYearArchive/VoucherDetail', [
            'fiscalYear' => $this->fiscalYearProp($fiscalYearArchive),
            'archive' => $this->archiveProp($fiscalYearArchive),
            'voucher' => [
                'id' => $voucher->id,
                'voucherType' => $voucher->voucher_type,
                'voucherNumber' => $voucher->voucher_number,
                'date' => $voucher->date,
                'narration' => $voucher->narration,
                'reason' => $voucher->reason,
                'createdByName' => $voucher->created_by_name,
            ],
            'lines' => $lines,
        ]);
    }

    /**
     * An archive file is always SQLite, which gives its decimal columns REAL
     * affinity - so a plain SUM() over an archived voucher's lines comes back
     * as a float and an ordinary total reads as 2261.1000000000004. The SUM
     * is taken on scaled integers instead and divided back here, which is
     * lossless at the two decimals a Money holds.
     */
    private function fromScaled(int|float|string $scaled): Money
    {
        return Money::of(BigDecimal::of((int) $scaled)->dividedBy(100, 2, RoundingMode::Unnecessary));
    }

    /**
     * @return array{id: int, name: string, bsLabel: string, startDate: string, endDate: string}
     */
    private function fiscalYearProp(FiscalYearArchive $fiscalYearArchive): array
    {
        $fiscalYear = $fiscalYearArchive->fiscalYear;

        return [
            'id' => $fiscalYear->id,
            'name' => $fiscalYear->name,
            'bsLabel' => $fiscalYear->bsLabel,
            'startDate' => $fiscalYear->start_date->toDateString(),
            'endDate' => $fiscalYear->end_date->toDateString(),
        ];
    }

    /**
     * @return array{id: int, archivedAt: string, archivedBy: string, voucherCount: int, lineCount: int}
     */
    private function archiveProp(FiscalYearArchive $fiscalYearArchive): array
    {
        return [
            'id' => $fiscalYearArchive->id,
            'archivedAt' => $fiscalYearArchive->archived_at->toDateTimeString(),
            'archivedBy' => $fiscalYearArchive->archiver?->name ?? 'Unknown',
            'voucherCount' => $fiscalYearArchive->voucher_count,
            'lineCount' => $fiscalYearArchive->line_count,
        ];
    }
}
