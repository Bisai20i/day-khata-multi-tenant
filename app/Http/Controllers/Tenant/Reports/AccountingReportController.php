<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Exports\AccountBookExport;
use App\Exports\BalanceSheetExport;
use App\Exports\CancelledDocumentsExport;
use App\Exports\DayBookExport;
use App\Exports\IncomeStatementExport;
use App\Exports\TrialBalanceExport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountHead;
use App\Models\CapitalPurchase;
use App\Models\CapitalSale;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * Trial Balance / Income Statement / Balance Sheet / Day Book / Cash Book /
 * Bank Book - read-only aggregations over JournalVoucherLine, no new tables.
 *
 * Three rules run through every method here.
 *
 * **Everything is a Money string.** Props leave this controller as exact
 * 2-decimal strings, never floats: the pages render them with formatMoney()
 * and never do arithmetic of their own (CONTRACTS C1/C8). Sums run on scaled
 * integers inside SQL, because SQLite gives a decimal column REAL affinity
 * and a plain SUM() there reintroduces the float error the whole Phase 1
 * foundation exists to remove.
 *
 * **Every figure is boxed inside ONE fiscal year.** The Cash Book used to
 * carry its opening balance from every line ever posted, including the new
 * year's own Opening Balance voucher, which restates the same closing
 * balances the previous year's lines already produced - so cash that ended
 * FY1 at 600 opened FY2 at 1,200 (audit P0-18). An opening balance is now
 * "this year's Opening Balance voucher plus this year's lines before the
 * window", and nothing ever sums across a year boundary.
 *
 * **Inventory is periodic** (CONTRACTS C10). Stock documents post no
 * journal, so a year that has not been closed yet has no stock in its
 * ledger at all. The statements compute it instead, through StockCosting,
 * and label it as computed. A closed year shows the entries
 * FiscalYear::close() actually posted.
 */
class AccountingReportController extends Controller
{
    public function trialBalance(Request $request): Response
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        $accountId = $request->integer('account_id') ?: null;

        $heads = [];
        $totals = $this->emptyTrialBalanceTotals();
        [$from, $to] = [null, null];

        if ($fiscalYear !== null) {
            [$from, $to] = $this->resolveWindow($request, $fiscalYear);

            $excluded = $this->sweepVoucherIds($fiscalYear);
            $opening = $this->openingBalancesByAccount($fiscalYear, $excluded, $from);
            $period = $this->balancesByAccount($fiscalYear, [...$excluded, ...$this->openingVoucherIds($fiscalYear)], $from, $to);

            $rows = $this->trialBalanceRows($opening, $period, $accountId);
            $heads = $this->buildHierarchy($rows);
            $totals = $this->trialBalanceTotals($rows);
        }

        return Inertia::render('Tenant/Reports/TrialBalance', array_merge([
            'fiscalYears' => $this->fiscalYearOptions(),
            'fiscalYearId' => $fiscalYear?->id,
            // Legacy's `?accno=` single-ledger filter (audit T15-5): narrows
            // the whole report to one account without a separate endpoint.
            'accounts' => Account::orderBy('name')->get(['id', 'code', 'name']),
            'accountId' => $accountId,
            'from' => $from,
            'to' => $to,
            'heads' => $heads,
        ], $totals));
    }

    public function incomeStatement(Request $request): Response
    {
        $fiscalYear = $this->resolveFiscalYear($request);

        $income = [];
        $expenses = [];
        $totalIncome = Money::zero();
        $totalExpenses = Money::zero();
        $grossProfit = Money::zero();
        $stock = ['opening' => '0.00', 'closing' => '0.00', 'posted' => false, 'asOf' => null];
        [$from, $to] = [null, null];

        if ($fiscalYear !== null) {
            [$from, $to] = $this->resolveWindow($request, $fiscalYear);

            $excluded = $this->sweepVoucherIds($fiscalYear);
            $balances = $this->balancesByAccount($fiscalYear, $excluded, $from, $to);

            $stock = $this->stockPosition($fiscalYear, $to);

            $income = $this->headBalances('Income', $balances, creditNormal: true);
            $expenses = $this->headBalances('Expenses', $balances, creditNormal: false);

            // A year whose trading entries have not been posted yet (it is
            // still open, or it was closed before this feature existed) has
            // no stock anywhere in its ledger, so the two sides are shown as
            // computed rows. Once close() has posted them, the real EXE9 /
            // INI22 balances are already in $balances and adding them again
            // would double the stock movement.
            if (! $stock['posted']) {
                $expenses = $this->withVirtualRow($expenses, FiscalYear::OPENING_STOCK_CODE, 'Opening Stock', $stock['opening']);
                $income = $this->withVirtualRow($income, FiscalYear::CLOSING_STOCK_CODE, 'Closing Stock', $stock['closing']);
            }

            $totalIncome = Money::sum(array_column($income, 'amount'));
            $totalExpenses = Money::sum(array_column($expenses, 'amount'));
            $grossProfit = $this->grossProfit($income, $expenses);
        }

        return Inertia::render('Tenant/Reports/IncomeStatement', [
            'fiscalYears' => $this->fiscalYearOptions(),
            'fiscalYearId' => $fiscalYear?->id,
            'from' => $from,
            'to' => $to,
            'income' => $income,
            'expenses' => $expenses,
            'stock' => $stock,
            'totalIncome' => $totalIncome->toString(),
            'totalExpenses' => $totalExpenses->toString(),
            'grossProfit' => $grossProfit->toString(),
            'netProfit' => $totalIncome->minus($totalExpenses)->toString(),
        ]);
    }

    public function balanceSheet(Request $request): Response
    {
        $fiscalYear = $this->resolveFiscalYear($request);

        $heads = [];
        $totalAssets = Money::zero();
        $totalLiabilitiesAndCapital = Money::zero();
        $currentYearEarnings = Money::zero();
        $stock = ['opening' => '0.00', 'closing' => '0.00', 'posted' => false, 'asOf' => null];
        $balanceWarning = null;
        [$from, $to] = [null, null];

        if ($fiscalYear !== null) {
            [$from, $to] = $this->resolveWindow($request, $fiscalYear);

            // A balance sheet is a position "as at", so it always reads the
            // whole year up to $to - the window's `from` only moves the
            // Trial Balance's opening column and the Income Statement's
            // period, never a closing position.
            //
            // Nothing is excluded here, unlike the other two reports: the
            // sweep retargets profit-and-loss accounts (which a balance
            // sheet never shows) onto "Profit & Loss", a Capital account
            // whose balance this report absolutely does need.
            $balances = $this->balancesByAccount($fiscalYear, [], null, $to);

            $rows = $this->accountRows($balances, ['Assets', 'Liabilities', 'Capital']);

            $stock = $this->stockPosition($fiscalYear, $to);

            // Unswept profit for EVERY year, not only an open one. A closed
            // year stays Closed after being reopened for a correction, and
            // that correction lands on P&L accounts the sweep had already
            // zeroed - so a status check hid exactly the case that unbalances
            // the report (audit P0-19). This formula is zero by construction
            // for a cleanly closed year, and relock() re-sweeps whatever a
            // correction left behind.
            $currentYearEarnings = $this->unsweptProfitAndLoss($fiscalYear, $to);

            // Stock the ledger does not know about yet (see stockPosition()).
            // It is an asset AND the same amount of profit, so adding it to
            // both sides keeps the sheet balanced instead of tipping it.
            $stockAdjustment = Money::of($stock['closing'])->minus(Money::of($stock['opening']));

            if (! $stock['posted'] && ! $stockAdjustment->isZero()) {
                $rows = $this->withStockInHandRow($rows, $stockAdjustment);
                $currentYearEarnings = $currentYearEarnings->plus($stockAdjustment);
            }

            $assetRows = $rows->filter(fn (array $row) => $row['headName'] === 'Assets');
            $otherRows = $rows->filter(fn (array $row) => $row['headName'] !== 'Assets');

            $totalAssets = Money::sum($assetRows->pluck('debit'))->minus(Money::sum($assetRows->pluck('credit')));
            $totalLiabilitiesAndCapital = Money::sum($otherRows->pluck('credit'))
                ->minus(Money::sum($otherRows->pluck('debit')))
                ->plus($currentYearEarnings);

            $balanceWarning = $this->assertBalanced($fiscalYear, $totalAssets, $totalLiabilitiesAndCapital);
            $heads = $this->buildHierarchy($rows);
        }

        return Inertia::render('Tenant/Reports/BalanceSheet', [
            'fiscalYears' => $this->fiscalYearOptions(),
            'fiscalYearId' => $fiscalYear?->id,
            'from' => $from,
            'to' => $to,
            'heads' => $heads,
            'stock' => $stock,
            'currentYearEarnings' => $currentYearEarnings->toString(),
            'totalAssets' => $totalAssets->toString(),
            'totalLiabilitiesAndCapital' => $totalLiabilitiesAndCapital->toString(),
            'balanceWarning' => $balanceWarning,
        ]);
    }

    /**
     * A complete chronological diary of every voucher posted inside the
     * chosen fiscal year's window, lines nested underneath - unlike Trial
     * Balance/Income Statement this is an audit trail, not a balance
     * computation, so ClosingEntry/OpeningBalance vouchers are NOT excluded.
     */
    public function dayBook(Request $request): Response
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        $voucherType = $request->string('voucher_type')->toString() ?: null;

        $vouchers = collect();
        [$from, $to] = [null, null];

        if ($fiscalYear !== null) {
            [$from, $to] = $this->resolveWindow($request, $fiscalYear);

            $vouchers = JournalVoucher::query()
                ->with('lines.account:id,code,name')
                ->where('fiscal_year_id', $fiscalYear->id)
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to)
                ->when($voucherType !== null, fn (Builder $query) => $query->where('voucher_type', $voucherType))
                ->orderBy('date')
                ->orderBy('id')
                ->get();
        }

        $lines = $vouchers->flatMap->lines;

        return Inertia::render('Tenant/Reports/DayBook', [
            'fiscalYears' => $this->fiscalYearOptions(),
            'fiscalYearId' => $fiscalYear?->id,
            'from' => $from,
            'to' => $to,
            'voucherType' => $voucherType,
            'voucherTypeOptions' => array_map(fn (VoucherType $type) => $type->value, VoucherType::cases()),
            'vouchers' => $vouchers->map(fn (JournalVoucher $voucher) => [
                'date' => $voucher->date->toDateString(),
                'voucherType' => $voucher->voucher_type->value,
                'voucherNumber' => $voucher->voucher_number,
                'narration' => $voucher->narration,
                'lines' => $voucher->lines->map(fn (JournalVoucherLine $line) => [
                    'accountCode' => $line->account->code,
                    'accountName' => $line->account->name,
                    'debit' => Money::of($line->debit)->toString(),
                    'credit' => Money::of($line->credit)->toString(),
                    'narration' => $line->narration,
                ])->values(),
            ])->values(),
            'totalDebit' => Money::sum($lines->map(fn (JournalVoucherLine $line) => Money::of($line->debit)))->toString(),
            'totalCredit' => Money::sum($lines->map(fn (JournalVoucherLine $line) => Money::of($line->credit)))->toString(),
        ]);
    }

    /**
     * Fixed to the single seeded Cash-In-Hand account (code AS1) - same
     * hardcoded-account-code convention this app already relies on for
     * INI20/LIA20/ASA23/EXE8/CA2 elsewhere.
     */
    public function cashBook(Request $request): Response
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        $account = Account::where('code', 'AS1')->firstOrFail();

        [$from, $to] = $fiscalYear === null ? [null, null] : $this->resolveWindow($request, $fiscalYear);

        return Inertia::render('Tenant/Reports/CashBook', array_merge(
            [
                'fiscalYears' => $this->fiscalYearOptions(),
                'fiscalYearId' => $fiscalYear?->id,
                'account' => $account->only(['id', 'code', 'name']),
                'from' => $from,
                'to' => $to,
            ],
            $fiscalYear === null
                ? $this->emptyAccountBook()
                : $this->accountBook($account, $fiscalYear, $from, $to),
        ));
    }

    /**
     * There is no "is this a bank account" flag anywhere on the Account
     * model (confirmed via grep) - `bank_account_id` on Sale/Purchase is
     * just a free choice of any Account the user makes at posting time.
     * Rather than invent new schema/UI to classify accounts as banks, this
     * report is a plain account picker (same pattern as
     * Accounts/Ledger.vue's fiscal-year picker) - the user chooses which
     * non-cash account to view as a "bank book."
     */
    public function bankBook(Request $request): Response
    {
        $fiscalYear = $this->resolveFiscalYear($request);

        $accounts = Account::where('code', '!=', 'AS1')->orderBy('name')->get(['id', 'code', 'name']);
        $accountId = $request->integer('account_id') ?: $accounts->first()?->id;
        $account = $accountId ? $accounts->firstWhere('id', $accountId) : null;

        [$from, $to] = $fiscalYear === null ? [null, null] : $this->resolveWindow($request, $fiscalYear);

        return Inertia::render('Tenant/Reports/BankBook', array_merge(
            [
                'fiscalYears' => $this->fiscalYearOptions(),
                'fiscalYearId' => $fiscalYear?->id,
                'accounts' => $accounts,
                'accountId' => $accountId,
                'from' => $from,
                'to' => $to,
            ],
            $account && $fiscalYear
                ? $this->accountBook($account, $fiscalYear, $from, $to)
                : $this->emptyAccountBook(),
        ));
    }

    /**
     * PDF print of the Trial Balance (T14 task 2). Recomputes exactly what
     * trialBalance() shows on screen, from the same private helpers, so the
     * PDF can never disagree with the page it was printed from. Reports have
     * no PrintLog entry (C9's copy-numbering is for a single countable
     * document like an invoice or credit note; a "Trial Balance as of
     * today" has no such identity to count copies of).
     */
    public function trialBalancePdf(Request $request)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        $accountId = $request->integer('account_id') ?: null;

        [$from, $to] = $this->resolveWindow($request, $fiscalYear);
        $excluded = $this->sweepVoucherIds($fiscalYear);
        $opening = $this->openingBalancesByAccount($fiscalYear, $excluded, $from);
        $period = $this->balancesByAccount($fiscalYear, [...$excluded, ...$this->openingVoucherIds($fiscalYear)], $from, $to);
        $rows = $this->trialBalanceRows($opening, $period, $accountId);
        $heads = $this->buildHierarchy($rows);
        $totals = $this->trialBalanceTotals($rows);

        $pdf = Pdf::loadView('pdf.trial-balance', array_merge([
            'company' => CompanySetting::current(),
            'documentNumber' => "Trial Balance - {$fiscalYear->name}",
            'documentDate' => $to,
            'dateAd' => $to,
            'dateBs' => NepaliCalendar::formatBs($to),
            'fiscalYearName' => $fiscalYear->name,
            'heads' => $heads,
        ], $totals));

        return $pdf->stream("trial-balance-{$fiscalYear->name}.pdf");
    }

    public function trialBalanceExport(Request $request)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        $accountId = $request->integer('account_id') ?: null;

        [$from, $to] = $this->resolveWindow($request, $fiscalYear);
        $excluded = $this->sweepVoucherIds($fiscalYear);
        $opening = $this->openingBalancesByAccount($fiscalYear, $excluded, $from);
        $period = $this->balancesByAccount($fiscalYear, [...$excluded, ...$this->openingVoucherIds($fiscalYear)], $from, $to);
        $heads = $this->buildHierarchy($this->trialBalanceRows($opening, $period, $accountId));

        return Excel::download(new TrialBalanceExport($heads), "trial-balance-{$fiscalYear->name}.xlsx");
    }

    public function incomeStatementPdf(Request $request)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        $data = $this->incomeStatementData($request, $fiscalYear);

        $pdf = Pdf::loadView('pdf.income-statement', array_merge([
            'company' => CompanySetting::current(),
            'documentNumber' => "Income Statement - {$fiscalYear->name}",
            'documentDate' => $data['to'],
            'dateAd' => $data['to'],
            'dateBs' => NepaliCalendar::formatBs($data['to']),
            'fiscalYearName' => $fiscalYear->name,
        ], $data));

        return $pdf->stream("income-statement-{$fiscalYear->name}.pdf");
    }

    public function incomeStatementExport(Request $request)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        $data = $this->incomeStatementData($request, $fiscalYear);

        return Excel::download(
            new IncomeStatementExport($data['income'], $data['expenses'], $data['grossProfit'], $data['netProfit']),
            "income-statement-{$fiscalYear->name}.xlsx",
        );
    }

    /**
     * Shared by incomeStatement()'s Inertia page and its PDF/Excel siblings,
     * so all three read from one computation and can never disagree.
     *
     * @return array{from: string, to: string, income: array, expenses: array, totalIncome: string, totalExpenses: string, grossProfit: string, netProfit: string}
     */
    private function incomeStatementData(Request $request, FiscalYear $fiscalYear): array
    {
        [$from, $to] = $this->resolveWindow($request, $fiscalYear);

        $excluded = $this->sweepVoucherIds($fiscalYear);
        $balances = $this->balancesByAccount($fiscalYear, $excluded, $from, $to);
        $stock = $this->stockPosition($fiscalYear, $to);

        $income = $this->headBalances('Income', $balances, creditNormal: true);
        $expenses = $this->headBalances('Expenses', $balances, creditNormal: false);

        if (! $stock['posted']) {
            $expenses = $this->withVirtualRow($expenses, FiscalYear::OPENING_STOCK_CODE, 'Opening Stock', $stock['opening']);
            $income = $this->withVirtualRow($income, FiscalYear::CLOSING_STOCK_CODE, 'Closing Stock', $stock['closing']);
        }

        $totalIncome = Money::sum(array_column($income, 'amount'));
        $totalExpenses = Money::sum(array_column($expenses, 'amount'));
        $grossProfit = $this->grossProfit($income, $expenses);

        return [
            'from' => $from,
            'to' => $to,
            'income' => $income,
            'expenses' => $expenses,
            'totalIncome' => $totalIncome->toString(),
            'totalExpenses' => $totalExpenses->toString(),
            'grossProfit' => $grossProfit->toString(),
            'netProfit' => $totalIncome->minus($totalExpenses)->toString(),
        ];
    }

    public function balanceSheetPdf(Request $request)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        $data = $this->balanceSheetData($request, $fiscalYear);

        $pdf = Pdf::loadView('pdf.balance-sheet', array_merge([
            'company' => CompanySetting::current(),
            'documentNumber' => "Balance Sheet - {$fiscalYear->name}",
            'documentDate' => $data['to'],
            'dateAd' => $data['to'],
            'dateBs' => NepaliCalendar::formatBs($data['to']),
            'fiscalYearName' => $fiscalYear->name,
        ], $data));

        return $pdf->stream("balance-sheet-{$fiscalYear->name}.pdf");
    }

    public function balanceSheetExport(Request $request)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        $data = $this->balanceSheetData($request, $fiscalYear);

        return Excel::download(
            new BalanceSheetExport($data['heads'], $data['totalAssets'], $data['totalLiabilitiesAndCapital']),
            "balance-sheet-{$fiscalYear->name}.xlsx",
        );
    }

    /**
     * Shared by balanceSheet()'s Inertia page and its PDF/Excel siblings.
     *
     * @return array{to: string, heads: array, stock: array, currentYearEarnings: string, totalAssets: string, totalLiabilitiesAndCapital: string, balanceWarning: ?string}
     */
    private function balanceSheetData(Request $request, FiscalYear $fiscalYear): array
    {
        [, $to] = $this->resolveWindow($request, $fiscalYear);

        $balances = $this->balancesByAccount($fiscalYear, [], null, $to);
        $rows = $this->accountRows($balances, ['Assets', 'Liabilities', 'Capital']);
        $stock = $this->stockPosition($fiscalYear, $to);
        $currentYearEarnings = $this->unsweptProfitAndLoss($fiscalYear, $to);
        $stockAdjustment = Money::of($stock['closing'])->minus(Money::of($stock['opening']));

        if (! $stock['posted'] && ! $stockAdjustment->isZero()) {
            $rows = $this->withStockInHandRow($rows, $stockAdjustment);
            $currentYearEarnings = $currentYearEarnings->plus($stockAdjustment);
        }

        $assetRows = $rows->filter(fn (array $row) => $row['headName'] === 'Assets');
        $otherRows = $rows->filter(fn (array $row) => $row['headName'] !== 'Assets');

        $totalAssets = Money::sum($assetRows->pluck('debit'))->minus(Money::sum($assetRows->pluck('credit')));
        $totalLiabilitiesAndCapital = Money::sum($otherRows->pluck('credit'))
            ->minus(Money::sum($otherRows->pluck('debit')))
            ->plus($currentYearEarnings);

        return [
            'to' => $to,
            'heads' => $this->buildHierarchy($rows),
            'stock' => $stock,
            'currentYearEarnings' => $currentYearEarnings->toString(),
            'totalAssets' => $totalAssets->toString(),
            'totalLiabilitiesAndCapital' => $totalLiabilitiesAndCapital->toString(),
            'balanceWarning' => $this->assertBalanced($fiscalYear, $totalAssets, $totalLiabilitiesAndCapital),
        ];
    }

    public function dayBookPdf(Request $request)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        $data = $this->dayBookData($request, $fiscalYear);

        $pdf = Pdf::loadView('pdf.day-book', array_merge([
            'company' => CompanySetting::current(),
            'documentNumber' => "Day Book - {$fiscalYear->name}",
            'documentDate' => $data['to'],
            'dateAd' => $data['to'],
            'dateBs' => NepaliCalendar::formatBs($data['to']),
            'fiscalYearName' => $fiscalYear->name,
        ], $data));

        return $pdf->stream("day-book-{$fiscalYear->name}.pdf");
    }

    public function dayBookExport(Request $request)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        $data = $this->dayBookData($request, $fiscalYear);

        return Excel::download(new DayBookExport($data['vouchers']), "day-book-{$fiscalYear->name}.xlsx");
    }

    /**
     * @return array{from: string, to: string, vouchers: array, totalDebit: string, totalCredit: string}
     */
    private function dayBookData(Request $request, FiscalYear $fiscalYear): array
    {
        $voucherType = $request->string('voucher_type')->toString() ?: null;
        [$from, $to] = $this->resolveWindow($request, $fiscalYear);

        $vouchers = JournalVoucher::query()
            ->with('lines.account:id,code,name')
            ->where('fiscal_year_id', $fiscalYear->id)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($voucherType !== null, fn (Builder $query) => $query->where('voucher_type', $voucherType))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $lines = $vouchers->flatMap->lines;

        return [
            'from' => $from,
            'to' => $to,
            'vouchers' => $vouchers->map(fn (JournalVoucher $voucher) => [
                'date' => $voucher->date->toDateString(),
                'voucherType' => $voucher->voucher_type->value,
                'voucherNumber' => $voucher->voucher_number,
                'narration' => $voucher->narration,
                'lines' => $voucher->lines->map(fn (JournalVoucherLine $line) => [
                    'accountCode' => $line->account->code,
                    'accountName' => $line->account->name,
                    'debit' => Money::of($line->debit)->toString(),
                    'credit' => Money::of($line->credit)->toString(),
                    'narration' => $line->narration,
                ])->values()->all(),
            ])->values()->all(),
            'totalDebit' => Money::sum($lines->map(fn (JournalVoucherLine $line) => Money::of($line->debit)))->toString(),
            'totalCredit' => Money::sum($lines->map(fn (JournalVoucherLine $line) => Money::of($line->credit)))->toString(),
        ];
    }

    public function cashBookPdf(Request $request)
    {
        return $this->accountBookPdf($request, Account::where('code', 'AS1')->firstOrFail(), 'Cash Book');
    }

    public function cashBookExport(Request $request)
    {
        return $this->accountBookExport($request, Account::where('code', 'AS1')->firstOrFail(), 'cash-book');
    }

    public function bankBookPdf(Request $request)
    {
        $account = Account::findOrFail($request->integer('account_id'));

        return $this->accountBookPdf($request, $account, 'Bank Book');
    }

    public function bankBookExport(Request $request)
    {
        $account = Account::findOrFail($request->integer('account_id'));

        return $this->accountBookExport($request, $account, 'bank-book');
    }

    private function accountBookPdf(Request $request, Account $account, string $title)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        [$from, $to] = $this->resolveWindow($request, $fiscalYear);
        $data = $this->accountBook($account, $fiscalYear, $from, $to);

        $pdf = Pdf::loadView('pdf.account-book', array_merge([
            'title' => "{$title} - {$account->name}",
            'company' => CompanySetting::current(),
            'documentNumber' => $account->code ? "{$account->code} - {$account->name}" : $account->name,
            'documentDate' => $to,
            'dateAd' => $to,
            'dateBs' => NepaliCalendar::formatBs($to),
            'fiscalYearName' => $fiscalYear->name,
            'from' => $from,
            'to' => $to,
        ], $data));

        return $pdf->stream(Str::slug($title).'-'.$account->id.'.pdf');
    }

    private function accountBookExport(Request $request, Account $account, string $filenamePrefix)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        [$from, $to] = $this->resolveWindow($request, $fiscalYear);
        $data = $this->accountBook($account, $fiscalYear, $from, $to);

        return Excel::download(
            new AccountBookExport($data['entries'], $data['openingBalance'], $data['closingBalance']),
            "{$filenamePrefix}-{$account->id}.xlsx",
        );
    }

    /**
     * Cancelled Documents report (T14 task 4): every cancelled sale,
     * purchase, return, receipt, payment, capital document and journal
     * voucher in one list - number, date, cancel date, who and reason
     * (CONTRACTS C5's four columns on each module table). A cash/bank
     * voucher (CONTRACTS/T14) has no owning module row, so it surfaces here
     * too, labelled by its own voucher type, through the Journal Vouchers
     * bucket below - JournalVoucher::sourceRecordLabel() is what keeps a
     * module-owned voucher (a cancelled Sale's underlying voucher, say) from
     * being listed a second time here as if it were a standalone one.
     */
    public function cancelledDocuments(Request $request): Response
    {
        return Inertia::render('Tenant/Reports/CancelledDocuments', [
            'rows' => $this->cancelledDocumentRows(),
        ]);
    }

    public function cancelledDocumentsExport(Request $request)
    {
        return Excel::download(new CancelledDocumentsExport($this->cancelledDocumentRows()), 'cancelled-documents.xlsx');
    }

    /**
     * @return array<int, array{type: string, number: string, date: string, cancelledAt: ?string, cancelledBy: ?string, reason: ?string}>
     */
    private function cancelledDocumentRows(): array
    {
        $rows = collect();

        $modules = [
            ['type' => 'Sale', 'model' => Sale::class, 'number' => fn (Sale $m) => $m->invoice_number ?? "#{$m->id}"],
            ['type' => 'Purchase', 'model' => Purchase::class, 'number' => fn (Purchase $m) => $m->bill_number ?: "#{$m->id}"],
            ['type' => 'Sales Return', 'model' => SalesReturn::class, 'number' => fn (SalesReturn $m) => $m->documentNumber()],
            ['type' => 'Purchase Return', 'model' => PurchaseReturn::class, 'number' => fn (PurchaseReturn $m) => $m->documentNumber()],
            ['type' => 'Receipt', 'model' => Receipt::class, 'number' => fn (Receipt $m) => "#{$m->id}"],
            ['type' => 'Payment', 'model' => Payment::class, 'number' => fn (Payment $m) => "#{$m->id}"],
            ['type' => 'Capital Sale', 'model' => CapitalSale::class, 'number' => fn (CapitalSale $m) => $m->documentNumber()],
            ['type' => 'Capital Purchase', 'model' => CapitalPurchase::class, 'number' => fn (CapitalPurchase $m) => $m->bill_number ?: "#{$m->id}"],
        ];

        foreach ($modules as $module) {
            $model = $module['model'];

            $model::query()
                ->where('status', 'cancelled')
                ->with('canceller:id,name')
                ->get()
                ->each(function ($record) use ($module, $rows) {
                    $rows->push([
                        'type' => $module['type'],
                        'number' => ($module['number'])($record),
                        'date' => $record->date?->toDateString() ?? '',
                        'cancelledAt' => $record->cancelled_at?->toDateTimeString(),
                        'cancelledBy' => $record->canceller?->name,
                        'reason' => $record->cancel_reason,
                    ]);
                });
        }

        // Journal Vouchers and cash/bank vouchers: only the ones with no
        // owning module record (see sourceRecordLabel()'s docblock) - every
        // other cancelled voucher_type belongs to one of the modules above
        // and is already listed under its own record.
        JournalVoucher::query()
            ->where('status', 'cancelled')
            ->whereIn('voucher_type', array_map(fn (VoucherType $t) => $t->value, VoucherType::manuallyCancellableTypes()))
            ->with('reversal.creator:id,name')
            ->get()
            ->each(function (JournalVoucher $voucher) use ($rows) {
                if ($voucher->sourceRecordLabel() !== null) {
                    return;
                }

                $reversal = $voucher->reversal;
                $reason = $reversal?->narration;
                // Strip the "Cancellation of ... : " prefix cancel() writes,
                // leaving just the reason the user typed.
                if ($reason !== null && str_contains($reason, ': ')) {
                    $reason = substr($reason, strrpos($reason, ': ') + 2);
                }

                $rows->push([
                    'type' => ucwords(str_replace('_', ' ', $voucher->voucher_type->value)),
                    'number' => "{$voucher->voucher_type->value}-{$voucher->voucher_number}",
                    'date' => $voucher->date->toDateString(),
                    'cancelledAt' => $reversal?->date?->toDateString(),
                    'cancelledBy' => $reversal?->creator?->name,
                    'reason' => $reason,
                ]);
            });

        return $rows->sortByDesc('cancelledAt')->values()->all();
    }

    /**
     * @return Collection<int, array{id: int, name: string, status: string, startDate: string, endDate: string}>
     */
    private function fiscalYearOptions(): Collection
    {
        return FiscalYear::query()
            ->orderByDesc('start_date')
            ->get(['id', 'name', 'status', 'start_date', 'end_date'])
            ->map(fn (FiscalYear $fiscalYear) => [
                'id' => $fiscalYear->id,
                'name' => $fiscalYear->name,
                'status' => $fiscalYear->status->value,
                'startDate' => $fiscalYear->start_date->toDateString(),
                'endDate' => $fiscalYear->end_date->toDateString(),
            ]);
    }

    private function resolveFiscalYear(Request $request): ?FiscalYear
    {
        $requested = $request->integer('fiscal_year_id');

        if ($requested) {
            return FiscalYear::find($requested) ?? FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();
        }

        return FiscalYear::query()->where('status', FiscalYearStatus::Open)->first()
            ?? FiscalYear::query()->orderByDesc('start_date')->first();
    }

    /**
     * The optional date window, always clamped inside the chosen fiscal
     * year. A report can no longer span two years (which is what let the
     * Cash Book double-count an opening balance), and a nonsensical window
     * falls back to the whole year rather than rendering an empty page.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveWindow(Request $request, FiscalYear $fiscalYear): array
    {
        $start = $fiscalYear->start_date->toDateString();
        $end = $fiscalYear->end_date->toDateString();

        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        $from = $from !== '' ? max($from, $start) : $start;
        $to = $to !== '' ? min($to, $end) : $end;

        return $from > $to ? [$start, $end] : [$from, $to];
    }

    /**
     * Ids of this year's P&L SWEEP vouchers, which Trial Balance and Income
     * Statement must leave out.
     *
     * FiscalYear::close() posts its sweep INTO the closing year itself,
     * zeroing every profit-and-loss account within that year's own line
     * set - so summing everything would make a closed year's trial balance
     * and income statement always report zero activity, which defeats the
     * point of either report.
     *
     * It is identified structurally rather than by voucher type, because
     * close() now posts THREE ClosingEntry vouchers and only one of them is
     * the sweep. By construction the sweep touches nothing but
     * profit-and-loss accounts plus "Profit & Loss" itself, while each of
     * the two trading-stock vouchers always pairs a stock account with the
     * balance-sheet account "Stock in Hand" - so "a ClosingEntry voucher
     * with no Stock in Hand line" is exactly the sweep, and stays exactly
     * the sweep even in the edge case where the year's net profit is zero
     * and the sweep carries no "Profit & Loss" line at all.
     *
     * @return array<int, int>
     */
    private function sweepVoucherIds(FiscalYear $fiscalYear): array
    {
        $stockInHandId = Account::where('code', FiscalYear::STOCK_IN_HAND_CODE)->value('id');

        return JournalVoucher::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('voucher_type', VoucherType::ClosingEntry->value)
            ->when(
                $stockInHandId !== null,
                fn (Builder $query) => $query->whereDoesntHave('lines', fn (Builder $lines) => $lines->where('account_id', $stockInHandId)),
            )
            ->pluck('id')
            ->all();
    }

    /**
     * Net (debit - credit) balance per account inside one fiscal year, in a
     * single grouped query.
     *
     * Scaled-integer SUM for the same reason FiscalYear::netBalance() and
     * StockCosting use it: SQLite gives a decimal column REAL affinity, so a
     * plain SUM() there comes back as a float. whereDate() rather than
     * whereBetween() because a `date`-cast column is stored as a full
     * "Y-m-d H:i:s" string on SQLite, which sorts AFTER the bare "Y-m-d" a
     * filter sends - so the last day of any range silently fell out of it.
     *
     * @param  array<int, int>  $excludeVoucherIds
     * @return array<int, Money>
     */
    private function balancesByAccount(FiscalYear $fiscalYear, array $excludeVoucherIds, ?string $from, ?string $to): array
    {
        $cast = JournalVoucherLine::query()->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        $rows = JournalVoucherLine::query()
            ->whereHas('journalVoucher', function (Builder $query) use ($fiscalYear, $excludeVoucherIds, $from, $to) {
                $query->where('fiscal_year_id', $fiscalYear->id)
                    ->when($excludeVoucherIds !== [], fn (Builder $q) => $q->whereNotIn('id', $excludeVoucherIds))
                    ->when($from !== null, fn (Builder $q) => $q->whereDate('date', '>=', $from))
                    ->when($to !== null, fn (Builder $q) => $q->whereDate('date', '<=', $to));
            })
            ->selectRaw(
                "account_id,
                 COALESCE(SUM(CAST(ROUND(debit * 100) AS {$cast})), 0) - COALESCE(SUM(CAST(ROUND(credit * 100) AS {$cast})), 0) as net_scaled"
            )
            ->groupBy('account_id')
            ->pluck('net_scaled', 'account_id');

        $balances = [];

        foreach ($rows as $accountId => $netScaled) {
            $balances[(int) $accountId] = Money::of(
                BigDecimal::of((int) $netScaled)->dividedBy(100, 2, RoundingMode::Unnecessary)
            );
        }

        return $balances;
    }

    /**
     * Ids of this fiscal year's Opening Balance vouchers. Trial Balance's
     * Period bucket must never include them (see openingBalancesByAccount()
     * below), matching accountBook()'s own `voucher_type != OpeningBalance`
     * period filter - otherwise the carried-forward balance would count
     * twice: once as Opening, once as Period.
     *
     * @return array<int, int>
     */
    private function openingVoucherIds(FiscalYear $fiscalYear): array
    {
        return JournalVoucher::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('voucher_type', VoucherType::OpeningBalance->value)
            ->pluck('id')
            ->all();
    }

    /**
     * Trial Balance's Opening column: this year's Opening Balance voucher
     * (whatever date it is posted on) PLUS this year's lines dated strictly
     * before `$from`. This is the same rule accountBook() already uses (see
     * its docblock at ~line 1290) - not a plain `date <= dayBefore($from)`
     * comparison, which used to render Opening as 0.00 for the default view
     * of every year after the first: FiscalYear::postOpeningBalances() dates
     * the Opening Balance voucher at exactly $fiscalYear->start_date, and
     * resolveWindow() defaults $from to that same start_date, so
     * dayBefore($from) landed one day before the fiscal year even starts and
     * the carry-forward silently fell into the Period bucket instead
     * (audit T15-1).
     *
     * $excludeVoucherIds is the sweepVoucherIds() exclusion, orthogonal to
     * the OpeningBalance question - a ClosingEntry sweep voucher must never
     * appear in Opening either.
     *
     * @param  array<int, int>  $excludeVoucherIds
     * @return array<int, Money>
     */
    private function openingBalancesByAccount(FiscalYear $fiscalYear, array $excludeVoucherIds, string $from): array
    {
        $cast = JournalVoucherLine::query()->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        $rows = JournalVoucherLine::query()
            ->whereHas('journalVoucher', function (Builder $query) use ($fiscalYear, $excludeVoucherIds, $from) {
                $query->where('fiscal_year_id', $fiscalYear->id)
                    ->when($excludeVoucherIds !== [], fn (Builder $q) => $q->whereNotIn('id', $excludeVoucherIds))
                    ->where(fn (Builder $q) => $q
                        ->where('voucher_type', VoucherType::OpeningBalance->value)
                        ->orWhereDate('date', '<', $from));
            })
            ->selectRaw(
                "account_id,
                 COALESCE(SUM(CAST(ROUND(debit * 100) AS {$cast})), 0) - COALESCE(SUM(CAST(ROUND(credit * 100) AS {$cast})), 0) as net_scaled"
            )
            ->groupBy('account_id')
            ->pluck('net_scaled', 'account_id');

        $balances = [];

        foreach ($rows as $accountId => $netScaled) {
            $balances[(int) $accountId] = Money::of(
                BigDecimal::of((int) $netScaled)->dividedBy(100, 2, RoundingMode::Unnecessary)
            );
        }

        return $balances;
    }

    /**
     * Unswept profit-and-loss for this year: every P&L account's balance
     * INCLUDING the closing entries. Zero for a cleanly closed year (that is
     * what the sweep achieved), the year's profit for an open one, and the
     * leftover effect of a correction for a reopened one.
     */
    private function unsweptProfitAndLoss(FiscalYear $fiscalYear, string $to): Money
    {
        $balances = $this->balancesByAccount($fiscalYear, [], null, $to);

        $profitAndLossAccountIds = Account::query()
            ->whereHas('group.accountHead', fn (Builder $query) => $query->where('is_profit_and_loss', true))
            ->orWhereHas('subgroup.accountGroup.accountHead', fn (Builder $query) => $query->where('is_profit_and_loss', true))
            ->pluck('id');

        $net = Money::zero();

        foreach ($profitAndLossAccountIds as $accountId) {
            $net = $net->plus($balances[(int) $accountId] ?? Money::zero());
        }

        // net is debit - credit across income and expenses, so profit is its
        // negation: income sits on the credit side.
        return $net->negated();
    }

    /**
     * This year's opening and closing stock, and whether the ledger already
     * holds them.
     *
     * "Posted" means FiscalYear::close() has run its trading pair for this
     * year - detected by the presence of a ClosingEntry voucher touching
     * Stock in Hand, the same structural marker sweepVoucherIds() keys off.
     *
     * Not posted yet:
     * - opening stock is the Stock in Hand balance this year was opened
     *   with, which is every non-closing-entry line on that account (only
     *   the opening-balance carry-forward and a manual opening-stock import
     *   ever touch it, since inventory is periodic);
     * - closing stock is computed live by StockCosting as at the report
     *   date, which is exactly what close() will post.
     *
     * @return array{opening: string, closing: string, posted: bool, asOf: string}
     */
    private function stockPosition(FiscalYear $fiscalYear, string $to): array
    {
        $stockInHand = Account::where('code', FiscalYear::STOCK_IN_HAND_CODE)->first();

        if (! $stockInHand) {
            return ['opening' => '0.00', 'closing' => '0.00', 'posted' => false, 'asOf' => $to];
        }

        $posted = JournalVoucher::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('voucher_type', VoucherType::ClosingEntry->value)
            ->whereHas('lines', fn (Builder $lines) => $lines->where('account_id', $stockInHand->id))
            ->exists();

        $excluded = $this->sweepVoucherIds($fiscalYear);
        $withoutSweep = $this->balancesByAccount($fiscalYear, $excluded, null, $fiscalYear->end_date->toDateString());

        if ($posted) {
            // After the pair, Stock in Hand nets to the posted closing value
            // for the year and the opening value is the trading debit that
            // moved out of it.
            $all = $this->balancesByAccount($fiscalYear, [], null, $fiscalYear->end_date->toDateString());
            $openingStockAccountId = Account::where('code', FiscalYear::OPENING_STOCK_CODE)->value('id');

            $opening = $openingStockAccountId === null
                ? Money::zero()
                : ($withoutSweep[(int) $openingStockAccountId] ?? Money::zero());

            return [
                'opening' => $opening->toString(),
                'closing' => ($all[$stockInHand->id] ?? Money::zero())->toString(),
                'posted' => true,
                'asOf' => $fiscalYear->end_date->toDateString(),
            ];
        }

        return [
            'opening' => ($withoutSweep[$stockInHand->id] ?? Money::zero())->toString(),
            'closing' => StockCosting::totalClosingValue($to)->toString(),
            'posted' => false,
            'asOf' => $to,
        ];
    }

    /**
     * Flat list of {account, head, group, subgroup, debit, credit} rows, one
     * per account within scope, optionally restricted to a set of head
     * names. Only one of debit/credit is ever nonzero: a positive net shows
     * as a debit balance, a negative one as a credit balance.
     *
     * Every account in scope is listed, including one with a zero balance
     * (audit T15-4) - legacy's Balance Sheet enumerates from the full chart
     * of accounts via a LEFT JOIN and only ever hides a zero row behind an
     * explicit "Hide Zero" toggle, so a brand-new ledger account with no
     * postings yet, or one that nets to exactly zero for the period, is
     * still real information (e.g. "this account exists and is currently
     * settled"), not something to silently drop.
     *
     * @param  array<int, Money>  $balances
     * @param  array<int, string>|null  $headNames
     * @return Collection<int, array{account: Account, headName: string, groupName: string, subgroupName: ?string, debit: Money, credit: Money}>
     */
    private function accountRows(array $balances, ?array $headNames = null): Collection
    {
        $accounts = Account::query()
            ->with(['group.accountHead', 'subgroup.accountGroup.accountHead'])
            ->when($headNames !== null, fn (Builder $query) => $query
                ->whereHas('group.accountHead', fn (Builder $q) => $q->whereIn('name', $headNames))
                ->orWhereHas('subgroup.accountGroup.accountHead', fn (Builder $q) => $q->whereIn('name', $headNames)))
            ->get();

        $rows = collect();

        foreach ($accounts as $account) {
            $head = $account->group?->accountHead ?? $account->subgroup?->accountGroup?->accountHead;
            $group = $account->group ?? $account->subgroup?->accountGroup;

            if (! $head || ! $group) {
                continue;
            }

            $net = $balances[$account->id] ?? Money::zero();

            $rows->push([
                'account' => $account,
                'headName' => $head->name,
                'groupName' => $group->name,
                'subgroupName' => $account->subgroup?->name,
                'debit' => $net->isPositive() ? $net : Money::zero(),
                'credit' => $net->isNegative() ? $net->negated() : Money::zero(),
            ]);
        }

        return $rows;
    }

    /**
     * Adds the not-yet-posted stock movement onto the Balance Sheet's Stock
     * in Hand row, creating the row when the account has no balance of its
     * own yet (a tenant's very first year).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function withStockInHandRow(Collection $rows, Money $adjustment): Collection
    {
        $stockInHand = Account::with(['group.accountHead', 'subgroup.accountGroup.accountHead'])
            ->where('code', FiscalYear::STOCK_IN_HAND_CODE)
            ->first();

        if (! $stockInHand) {
            return $rows;
        }

        $index = $rows->search(fn (array $row) => $row['account']->id === $stockInHand->id);

        if ($index !== false) {
            $row = $rows[$index];
            $net = $row['debit']->minus($row['credit'])->plus($adjustment);
            $row['debit'] = $net->isPositive() ? $net : Money::zero();
            $row['credit'] = $net->isNegative() ? $net->negated() : Money::zero();

            return $rows->replace([$index => $row]);
        }

        $head = $stockInHand->group?->accountHead ?? $stockInHand->subgroup?->accountGroup?->accountHead;
        $group = $stockInHand->group ?? $stockInHand->subgroup?->accountGroup;

        if (! $head || ! $group) {
            return $rows;
        }

        return $rows->push([
            'account' => $stockInHand,
            'headName' => $head->name,
            'groupName' => $group->name,
            'subgroupName' => $stockInHand->subgroup?->name,
            'debit' => $adjustment->isPositive() ? $adjustment : Money::zero(),
            'credit' => $adjustment->isNegative() ? $adjustment->negated() : Money::zero(),
        ]);
    }

    /**
     * Every account in the chart of accounts, one row each - not just the
     * ones with a nonzero opening or period balance (audit T15-4). Legacy's
     * `trailbalance()` runs a LEFT JOIN from `mainaccount` (the full chart),
     * so a brand-new ledger account with no postings yet, or one whose
     * period activity happens to net to exactly zero (e.g. a customer fully
     * settled during the period), still shows a 0.00 row rather than
     * disappearing; legacy only ever hides it behind an explicit "Hide Zero"
     * toggle, which is not on by default and has no equivalent here yet.
     *
     * $accountId is legacy's `?accno=` single-ledger filter (audit T15-5):
     * when given, every other account is left out and the report narrows to
     * that one ledger's opening/period/closing figures.
     *
     * @param  array<int, Money>  $opening
     * @param  array<int, Money>  $period
     * @return Collection<int, array<string, mixed>>
     */
    private function trialBalanceRows(array $opening, array $period, ?int $accountId = null): Collection
    {
        $accounts = Account::query()
            ->with(['group.accountHead', 'subgroup.accountGroup.accountHead'])
            ->when($accountId !== null, fn (Builder $query) => $query->where('id', $accountId))
            ->get();

        $rows = collect();

        foreach ($accounts as $account) {
            $head = $account->group?->accountHead ?? $account->subgroup?->accountGroup?->accountHead;
            $group = $account->group ?? $account->subgroup?->accountGroup;

            if (! $head || ! $group) {
                continue;
            }

            $openingNet = $opening[$account->id] ?? Money::zero();
            $periodNet = $period[$account->id] ?? Money::zero();
            $closingNet = $openingNet->plus($periodNet);

            $rows->push([
                'account' => $account,
                'headName' => $head->name,
                'groupName' => $group->name,
                'subgroupName' => $account->subgroup?->name,
                'openingDebit' => $openingNet->isPositive() ? $openingNet : Money::zero(),
                'openingCredit' => $openingNet->isNegative() ? $openingNet->negated() : Money::zero(),
                'periodDebit' => $periodNet->isPositive() ? $periodNet : Money::zero(),
                'periodCredit' => $periodNet->isNegative() ? $periodNet->negated() : Money::zero(),
                'closingDebit' => $closingNet->isPositive() ? $closingNet : Money::zero(),
                'closingCredit' => $closingNet->isNegative() ? $closingNet->negated() : Money::zero(),
                // The two columns the old report showed, kept so the page's
                // "Debit / Credit" pair still means the closing position.
                'debit' => $closingNet->isPositive() ? $closingNet : Money::zero(),
                'credit' => $closingNet->isNegative() ? $closingNet->negated() : Money::zero(),
            ]);
        }

        return $rows;
    }

    /**
     * @return array<string, string|bool>
     */
    private function emptyTrialBalanceTotals(): array
    {
        return [
            'totalOpeningDebit' => '0.00',
            'totalOpeningCredit' => '0.00',
            'totalPeriodDebit' => '0.00',
            'totalPeriodCredit' => '0.00',
            'totalDebit' => '0.00',
            'totalCredit' => '0.00',
            'inBalance' => true,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, string|bool>
     */
    private function trialBalanceTotals(Collection $rows): array
    {
        $sum = fn (string $key) => Money::sum($rows->pluck($key));

        $closingDebit = $sum('closingDebit');
        $closingCredit = $sum('closingCredit');

        return [
            'totalOpeningDebit' => $sum('openingDebit')->toString(),
            'totalOpeningCredit' => $sum('openingCredit')->toString(),
            'totalPeriodDebit' => $sum('periodDebit')->toString(),
            'totalPeriodCredit' => $sum('periodCredit')->toString(),
            'totalDebit' => $closingDebit->toString(),
            'totalCredit' => $closingCredit->toString(),
            'inBalance' => $closingDebit->isEqualTo($closingCredit),
        ];
    }

    /**
     * @param  array<int, Money>  $balances
     * @return array<int, array{id: ?int, code: ?string, name: string, amount: string, computed: bool}>
     */
    private function headBalances(string $headName, array $balances, bool $creditNormal): array
    {
        $head = AccountHead::where('name', $headName)->first();

        if (! $head) {
            return [];
        }

        $accounts = Account::query()
            ->whereHas('group', fn (Builder $query) => $query->where('account_head_id', $head->id))
            ->orWhereHas('subgroup.accountGroup', fn (Builder $query) => $query->where('account_head_id', $head->id))
            ->get();

        $rows = [];

        foreach ($accounts as $account) {
            $net = $balances[$account->id] ?? Money::zero();
            $amount = $creditNormal ? $net->negated() : $net;

            if ($amount->isZero()) {
                continue;
            }

            $rows[] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'amount' => $amount->toString(),
                'computed' => false,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array{id: ?int, code: ?string, name: string, amount: string, computed: bool}>  $rows
     * @return array<int, array{id: ?int, code: ?string, name: string, amount: string, computed: bool}>
     */
    private function withVirtualRow(array $rows, string $code, string $name, string $amount): array
    {
        if (Money::of($amount)->isZero()) {
            return $rows;
        }

        $rows[] = [
            'id' => null,
            'code' => $code,
            'name' => $name,
            'amount' => Money::of($amount)->toString(),
            'computed' => true,
        ];

        return $rows;
    }

    /**
     * Trading-account gross profit: sales plus closing stock, less purchases
     * and opening stock. Only the two trading groups take part - indirect
     * income and indirect expenses belong below the gross profit line.
     *
     * @param  array<int, array{code: ?string, amount: string}>  $income
     * @param  array<int, array{code: ?string, amount: string}>  $expenses
     */
    private function grossProfit(array $income, array $expenses): Money
    {
        $tradingCodes = fn (string $groupName) => Account::query()
            ->whereHas('group', fn (Builder $query) => $query->where('name', $groupName))
            ->pluck('code')
            ->filter()
            ->all();

        $salesCodes = [...$tradingCodes('Sales Accounts'), FiscalYear::CLOSING_STOCK_CODE];
        $purchaseCodes = [...$tradingCodes('Purchase Accounts'), FiscalYear::OPENING_STOCK_CODE];

        $total = function (array $rows, array $codes): Money {
            $matching = array_filter($rows, fn (array $row) => $row['code'] !== null && in_array($row['code'], $codes, true));

            return Money::sum(array_column($matching, 'amount'));
        };

        return $total($income, $salesCodes)->minus($total($expenses, $purchaseCodes));
    }

    /**
     * Assets must equal Liabilities + Capital, always. A mismatch is a real
     * bookkeeping defect (a half-posted close, a correction that never got
     * swept), so it fails loudly under test and shows the operator a visible
     * warning row in production rather than a silently wrong statement.
     */
    private function assertBalanced(FiscalYear $fiscalYear, Money $assets, Money $liabilitiesAndCapital): ?string
    {
        if ($assets->isEqualTo($liabilitiesAndCapital)) {
            return null;
        }

        $difference = $assets->minus($liabilitiesAndCapital);

        $message = "This balance sheet does not balance: assets {$assets->toString()} against liabilities and capital {$liabilitiesAndCapital->toString()}, a difference of {$difference->toString()}. Check \"{$fiscalYear->name}\" for a closing entry that did not complete.";

        if (app()->runningUnitTests()) {
            throw new RuntimeException($message);
        }

        return $message;
    }

    /**
     * @return array{entries: array<int, array<string, mixed>>, openingBalance: string, closingBalance: string}
     */
    private function emptyAccountBook(): array
    {
        return ['entries' => [], 'openingBalance' => '0.00', 'closingBalance' => '0.00'];
    }

    /**
     * A single account's running book over a window INSIDE one fiscal year.
     *
     * Opening balance = that year's Opening Balance voucher lines, plus that
     * year's lines dated before `$from`. Nothing is ever summed across a
     * year boundary: the old all-time cumulative opening counted the new
     * year's Opening Balance voucher on top of the previous year's own
     * lines, so a cash balance of 600 at the end of FY1 opened FY2 at 1,200
     * (audit P0-18).
     *
     * The two halves are deliberately disjoint - the entries list leaves out
     * Opening Balance vouchers entirely - so a window starting on the year's
     * first day cannot count the carry-forward twice.
     *
     * @return array{entries: array<int, array<string, mixed>>, openingBalance: string, closingBalance: string}
     */
    private function accountBook(Account $account, FiscalYear $fiscalYear, string $from, string $to): array
    {
        $cast = JournalVoucherLine::query()->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        $openingScaled = JournalVoucherLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalVoucher', function (Builder $query) use ($fiscalYear, $from) {
                $query->where('fiscal_year_id', $fiscalYear->id)
                    ->where(fn (Builder $q) => $q
                        ->where('voucher_type', VoucherType::OpeningBalance->value)
                        ->orWhereDate('date', '<', $from));
            })
            ->selectRaw(
                "COALESCE(SUM(CAST(ROUND(debit * 100) AS {$cast})), 0) - COALESCE(SUM(CAST(ROUND(credit * 100) AS {$cast})), 0) as net_scaled"
            )
            ->value('net_scaled');

        $openingBalance = Money::of(BigDecimal::of((int) $openingScaled)->dividedBy(100, 2, RoundingMode::Unnecessary));

        $lines = JournalVoucherLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalVoucher', function (Builder $query) use ($fiscalYear, $from, $to) {
                $query->where('fiscal_year_id', $fiscalYear->id)
                    ->where('voucher_type', '!=', VoucherType::OpeningBalance->value)
                    ->whereDate('date', '>=', $from)
                    ->whereDate('date', '<=', $to);
            })
            ->with('journalVoucher')
            ->get()
            ->sortBy([['journalVoucher.date', 'asc'], ['id', 'asc']])
            ->values();

        $runningBalance = $openingBalance;

        $entries = $lines->map(function (JournalVoucherLine $line) use (&$runningBalance) {
            $debit = Money::of($line->debit);
            $credit = Money::of($line->credit);
            $runningBalance = $runningBalance->plus($debit)->minus($credit);

            return [
                'date' => $line->journalVoucher->date->toDateString(),
                'voucherType' => $line->journalVoucher->voucher_type->value,
                'voucherNumber' => $line->journalVoucher->voucher_number,
                'narration' => $line->narration ?? $line->journalVoucher->narration,
                'debit' => $debit->toString(),
                'credit' => $credit->toString(),
                'balance' => $runningBalance->toString(),
            ];
        })->values()->all();

        return [
            'entries' => $entries,
            // Money never renders "-0.00" (CONTRACTS C1), so a zero balance
            // always prints as 0.00 whichever side it arrived from.
            'openingBalance' => $openingBalance->toString(),
            'closingBalance' => $runningBalance->toString(),
        ];
    }

    /**
     * Nests flat account rows into head -> group -> (subgroup, optional) ->
     * accounts, for the hierarchical Trial Balance / Balance Sheet pages.
     * Every Money is rendered to its exact string here, at the last possible
     * moment before the props leave the controller.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array{name: string, groups: array<int, array{name: string, accounts: array, subgroups: array}>}>
     */
    private function buildHierarchy(Collection $rows): array
    {
        $heads = [];
        $moneyKeys = [
            'debit', 'credit',
            'openingDebit', 'openingCredit', 'periodDebit', 'periodCredit', 'closingDebit', 'closingCredit',
        ];

        foreach ($rows as $row) {
            $account = $row['account'];
            $accountRow = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
            ];

            foreach ($moneyKeys as $key) {
                if (isset($row[$key])) {
                    $accountRow[$key] = $row[$key]->toString();
                }
            }

            $heads[$row['headName']] ??= ['name' => $row['headName'], 'groups' => []];
            $heads[$row['headName']]['groups'][$row['groupName']] ??= ['name' => $row['groupName'], 'accounts' => [], 'subgroups' => []];

            if ($row['subgroupName'] !== null) {
                $heads[$row['headName']]['groups'][$row['groupName']]['subgroups'][$row['subgroupName']] ??= [
                    'name' => $row['subgroupName'],
                    'accounts' => [],
                ];
                $heads[$row['headName']]['groups'][$row['groupName']]['subgroups'][$row['subgroupName']]['accounts'][] = $accountRow;
            } else {
                $heads[$row['headName']]['groups'][$row['groupName']]['accounts'][] = $accountRow;
            }
        }

        return collect($heads)->values()->map(function (array $head) {
            $head['groups'] = collect($head['groups'])->values()->map(function (array $group) {
                $group['subgroups'] = collect($group['subgroups'])->values()->all();

                return $group;
            })->all();

            return $head;
        })->all();
    }
}
