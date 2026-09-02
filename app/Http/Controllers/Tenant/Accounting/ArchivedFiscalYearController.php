<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use App\Models\FiscalYearArchive;
use App\Support\FiscalYear\FiscalYearArchiver;
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
            ->selectRaw('v.id, v.voucher_type, v.voucher_number, v.date, v.narration, v.reason, v.created_by_name, COALESCE(SUM(l.debit), 0) as total_debit, COALESCE(SUM(l.credit), 0) as total_credit')
            ->get()
            ->map(fn ($voucher) => [
                'id' => $voucher->id,
                'voucherType' => $voucher->voucher_type,
                'voucherNumber' => $voucher->voucher_number,
                'date' => $voucher->date,
                'narration' => $voucher->narration,
                'reason' => $voucher->reason,
                'createdByName' => $voucher->created_by_name,
                'totalDebit' => (float) $voucher->total_debit,
                'totalCredit' => (float) $voucher->total_credit,
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
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
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
