<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Exports\VatSummaryExport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalVoucherLine;
use App\Models\Store;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Net VAT payable or refundable for a filing period - the figure a Nepali
 * VAT return actually asks for.
 *
 * Every figure on this page is summed from **exactly the rows the two VAT
 * books and the two return registers render** (see
 * SalesPurchaseReportController), never from a second, parallel query. That
 * is deliberate: audit P0-20 found the summary and the books disagreeing
 * because they filtered differently, and a tenant filing from one while
 * their accountant checked the other had no way to see which was right.
 *
 * Output VAT = VAT of every sales invoice ISSUED in the period (ordinary and
 * capital), less the VAT of every cancellation whose Reversal voucher is
 * dated in the period, less the VAT of every posted credit note dated in the
 * period. Input VAT mirrors that through purchases, capital purchases and
 * debit notes. Net VAT Payable = net output - net input; positive is owed to
 * the tax authority, negative is a refundable or carry-forward credit.
 *
 * The reconciliation block closes the audit finding itself: it puts the
 * period's real ledger movement on Output VAT (LIA20) and Input VAT (ASA23)
 * next to this report's own total. The difference must be 0.00. Anything
 * else means VAT reached those accounts by a route this report does not
 * model - almost always a hand-written journal voucher - and the tenant
 * needs to see that before filing, not after.
 */
class VatSummaryReportController extends Controller
{
    /** Output VAT (a liability): credited by a sale, debited by a credit note. */
    private const OUTPUT_VAT_ACCOUNT_CODE = 'LIA20';

    /** Input VAT (an asset): debited by a purchase, credited by a debit note. */
    private const INPUT_VAT_ACCOUNT_CODE = 'ASA23';

    /**
     * Voucher types that restate balances rather than record period VAT
     * activity. A year's Opening Balance voucher carries the previous
     * year's closing VAT position forward, so counting it as movement would
     * make every period containing a year start look unreconciled.
     *
     * @var list<string>
     */
    private const RESTATEMENT_VOUCHER_TYPES = [
        VoucherType::OpeningBalance->value,
        VoucherType::ClosingEntry->value,
        VoucherType::RollForwardAdjustment->value,
    ];

    public function index(Request $request): Response
    {
        $summary = $this->computeSummary($request);

        return Inertia::render('Tenant/Reports/VatSummary', [
            ...$summary,
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Same filtered figures as index(), streamed as a real .xlsx - built
     * from the exact same computeSummary() call so the export can never
     * drift from what is on screen and always honours the applied filters.
     */
    public function export(Request $request)
    {
        $summary = $this->computeSummary($request);

        return Excel::download(
            new VatSummaryExport($summary['outputVat'], $summary['inputVat'], $summary['netVatPayable'], $summary['reconciliation']),
            "vat-summary-{$summary['from']}-to-{$summary['to']}.xlsx",
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function computeSummary(Request $request): array
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;

        $books = new SalesPurchaseReportController;

        $outputVat = $this->side(
            $books->salesVatBookPayload($from, $to, $storeId)['rows'],
            $books->salesReturnRegisterPayload($from, $to, $storeId)['rows'],
        );

        $inputVat = $this->side(
            $books->purchaseVatBookPayload($from, $to, $storeId)['rows'],
            $books->purchaseReturnRegisterPayload($from, $to, $storeId)['rows'],
        );

        $netVatPayable = Money::of($outputVat['net'])->minus(Money::of($inputVat['net']));

        return [
            'outputVat' => $outputVat,
            'inputVat' => $inputVat,
            'netVatPayable' => $netVatPayable->toString(),
            'reconciliation' => $this->reconciliation($from, $to, $storeId, $outputVat['net'], $inputVat['net'], $netVatPayable),
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
        ];
    }

    /**
     * One side of the return (output or input), split the way the VAT form
     * asks for it.
     *
     * `gross` is ordinary invoices, `capital` is capital documents, both
     * counted in the period they were ISSUED in even when the document was
     * later cancelled. `cancelled` is what the period's own cancellations
     * take back out, `returns` what its credit or debit notes take out. The
     * book rows already carry cancellations as negative amounts, so this
     * only has to re-sign them for display.
     *
     * `fixedAssetVat` (T15-3) is purely an informational breakdown OF
     * `gross`: the slice of gross's own VAT that sits on a line inside an
     * ordinary Purchase posted to a Fixed Assets account (as opposed to a
     * whole CapitalPurchase document, which is `capital`). It is never
     * subtracted from `gross` or from `net`, so both keep tying to the
     * ledger reconciliation exactly as before this field was added.
     *
     * @param  Collection<int, array<string, mixed>>  $bookRows
     * @param  Collection<int, array<string, mixed>>  $returnRows
     * @return array{gross: string, capital: string, fixedAssetVat: string, cancelled: string, returns: string, net: string}
     */
    private function side(Collection $bookRows, Collection $returnRows): array
    {
        $issued = $bookRows->filter(fn (array $row) => $row['entry'] === 'issued');
        $issuedGross = $issued->reject(fn (array $row) => $row['capital']);

        $gross = $this->sumVat($issuedGross);
        $capital = $this->sumVat($issued->filter(fn (array $row) => $row['capital']));
        $fixedAssetVat = $this->sumVat($issuedGross, 'fixed_asset_vat_amount');
        $cancelled = $this->sumVat($bookRows->filter(fn (array $row) => $row['entry'] === 'cancelled'))->negated();
        $returns = $this->sumVat($returnRows);

        return [
            'gross' => $gross->toString(),
            'capital' => $capital->toString(),
            'fixedAssetVat' => $fixedAssetVat->toString(),
            'cancelled' => $cancelled->toString(),
            'returns' => $returns->toString(),
            'net' => $gross->plus($capital)->minus($cancelled)->minus($returns)->toString(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function sumVat(Collection $rows, string $key = 'vat_amount'): Money
    {
        return Money::sum($rows->map(fn (array $row) => Money::of($row[$key] ?? '0.00')));
    }

    /**
     * The period's real movement on the two VAT control accounts, beside
     * this report's own totals.
     *
     * A store filter has no ledger counterpart (the chart of accounts is not
     * store-scoped), so the comparison is only meaningful for the whole
     * business; with a store selected the block reports itself as not
     * applicable rather than showing a difference that means nothing.
     *
     * @return array<string, mixed>
     */
    private function reconciliation(string $from, string $to, ?int $storeId, string $reportOutput, string $reportInput, Money $reportNet): array
    {
        if ($storeId !== null) {
            return ['applicable' => false];
        }

        $ledgerOutput = $this->ledgerMovement(self::OUTPUT_VAT_ACCOUNT_CODE, $from, $to, creditNormal: true);
        $ledgerInput = $this->ledgerMovement(self::INPUT_VAT_ACCOUNT_CODE, $from, $to, creditNormal: false);
        $ledgerNet = $ledgerOutput->minus($ledgerInput);

        return [
            'applicable' => true,
            'ledgerOutputVat' => $ledgerOutput->toString(),
            'reportOutputVat' => $reportOutput,
            'outputDifference' => $ledgerOutput->minus(Money::of($reportOutput))->toString(),
            'ledgerInputVat' => $ledgerInput->toString(),
            'reportInputVat' => $reportInput,
            'inputDifference' => $ledgerInput->minus(Money::of($reportInput))->toString(),
            'ledgerNetVatPayable' => $ledgerNet->toString(),
            'reportNetVatPayable' => $reportNet->toString(),
            'difference' => $ledgerNet->minus($reportNet)->toString(),
        ];
    }

    /**
     * Net movement on one control account between two dates.
     *
     * Summed in SQL as a scaled integer: a plain `SUM(decimal)` comes back
     * as a float on SQLite (a DECIMAL column has REAL affinity there), which
     * is exactly the precision loss this rewrite removed everywhere else.
     * Multiplying by 100 and casting to an integer inside SQL is exact on
     * both engines, and dividing back is lossless at two decimals.
     *
     * Every voucher line counts regardless of `journal_vouchers.status`:
     * status is informational, and a cancellation posts its own mirrored
     * Reversal voucher rather than deleting the original lines.
     */
    private function ledgerMovement(string $accountCode, string $from, string $to, bool $creditNormal): Money
    {
        $accountId = Account::query()->where('code', $accountCode)->value('id');

        if ($accountId === null) {
            return Money::zero();
        }

        $cast = (new JournalVoucherLine)->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';
        $debit = "CAST(ROUND(journal_voucher_lines.debit * 100) AS {$cast})";
        $credit = "CAST(ROUND(journal_voucher_lines.credit * 100) AS {$cast})";
        $expression = $creditNormal ? "SUM({$credit}) - SUM({$debit})" : "SUM({$debit}) - SUM({$credit})";

        $netScaled = JournalVoucherLine::query()
            ->join('journal_vouchers', 'journal_vouchers.id', '=', 'journal_voucher_lines.journal_voucher_id')
            ->where('journal_voucher_lines.account_id', $accountId)
            ->whereDate('journal_vouchers.date', '>=', $from)
            ->whereDate('journal_vouchers.date', '<=', $to)
            ->whereNotIn('journal_vouchers.voucher_type', self::RESTATEMENT_VOUCHER_TYPES)
            ->selectRaw("{$expression} as net_scaled")
            ->value('net_scaled');

        return Money::of(BigDecimal::of((int) $netScaled)->dividedBy(100, 2, RoundingMode::Unnecessary));
    }

    /**
     * Defaults to the current open fiscal year's date range when no
     * explicit `from`/`to` query params are given, falling back to
     * month-to-date if no fiscal year exists yet.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(Request $request): array
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        if ($from !== '' && $to !== '') {
            return [$from, $to];
        }

        $fiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

        if ($fiscalYear) {
            return [$fiscalYear->start_date->toDateString(), $fiscalYear->end_date->toDateString()];
        }

        return [now()->startOfMonth()->toDateString(), now()->toDateString()];
    }
}
