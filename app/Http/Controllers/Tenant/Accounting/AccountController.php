<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Exports\AccountBookExport;
use App\Http\Controllers\Concerns\ImportsCsv;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AccountSubgroup;
use App\Models\CapitalPurchase;
use App\Models\CapitalSale;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountController extends Controller
{
    use ImportsCsv;

    /**
     * Columns of the opening-balance bulk-import template. An account is
     * matched by code first (unique, when present) and falls back to an
     * exact case-insensitive name match - customer/supplier ledger accounts
     * (see HasLedgerAccount) are never given a code, so name is the only way
     * to reach those. See importOpeningBalances()'s docblock for why the
     * whole file is imported atomically rather than skip-and-continue.
     *
     * @var list<string>
     */
    private const OPENING_BALANCE_IMPORT_COLUMNS = ['code', 'name', 'debit', 'credit'];

    /**
     * The narration every opening-balance import writes on its voucher, and
     * the only thing that marks a voucher as import-created.
     *
     * It is deliberately the single marker: reversing or replacing an import
     * is allowed ONLY for a voucher carrying it, so the year-end
     * carry-forward's own OpeningBalance voucher (narration "Opening balances
     * carried forward from ...", written by FiscalYear::close()) can never be
     * undone from this screen - that would silently delete the prior year's
     * closing position.
     */
    private const OPENING_BALANCE_IMPORT_NARRATION = 'Opening balance import';

    /**
     * Accounts an opening-balance import may never touch.
     *
     * AS11 (Opening Stock) is valued from the opening-stock import instead, so
     * that the stock ledger and the stock account can never disagree; a
     * profit-and-loss account has no opening balance by definition (it starts
     * every year at zero), and giving it one restates last year's result.
     */
    private const OPENING_BALANCE_BLOCKED_CODES = ['AS11'];

    public function index(): Response
    {
        return Inertia::render('Tenant/Accounting/Accounts/Index', [
            'accountGroups' => AccountGroup::query()->orderBy('name')->get(['id', 'name']),
            'accountSubgroups' => AccountSubgroup::query()->orderBy('name')->get(['id', 'account_group_id', 'name']),
            'accounts' => Account::query()->with(['group:id,name', 'subgroup:id,name'])->orderBy('name')->get(),
            'openingBalanceImports' => $this->openingBalanceImports(),
        ]);
    }

    /**
     * Every opening-balance import posted so far, newest first, with the one
     * that is still in effect flagged so the Accounts page can offer to clear
     * it. Cancelled ones stay listed: the reversal is part of the audit trail,
     * not something to hide.
     *
     * @return list<array{id: int, date: string, status: string, line_count: int, total: string, fiscal_year: string|null, can_clear: bool}>
     */
    private function openingBalanceImports(): array
    {
        $openFiscalYearId = FiscalYear::query()->where('status', FiscalYearStatus::Open)->value('id');

        return JournalVoucher::query()
            ->where('voucher_type', VoucherType::OpeningBalance)
            ->where('narration', self::OPENING_BALANCE_IMPORT_NARRATION)
            ->with('fiscalYear:id,name')
            ->withCount('lines')
            ->orderByDesc('id')
            ->get()
            ->map(fn (JournalVoucher $voucher): array => [
                'id' => $voucher->id,
                'date' => $voucher->date->toDateString(),
                'status' => $voucher->status,
                'line_count' => $voucher->lines_count,
                'total' => Money::sum($voucher->lines()->pluck('debit'))->toString(),
                'fiscal_year' => $voucher->fiscalYear?->name,
                // A reversal is dated today, so it can only land in the open
                // year: an import from a year that has since closed stays on
                // the ledger as history (CONTRACTS C4).
                'can_clear' => $voucher->status === 'posted' && $voucher->fiscal_year_id === $openFiscalYearId,
            ])
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        Account::create($this->validated($request));

        return redirect()->route('tenant.accounts.index')->with('status', 'Account added.');
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $data = $this->validated($request, $account);

        try {
            $account->update($data);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['account' => $e->getMessage()]);
        }

        return redirect()->route('tenant.accounts.index')->with('status', 'Account updated.');
    }

    public function destroy(Account $account): RedirectResponse
    {
        try {
            $account->delete();
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['account' => $e->getMessage()]);
        }

        return redirect()->route('tenant.accounts.index')->with('status', 'Account deleted.');
    }

    /**
     * The account ledger: an arbitrary date window inside one fiscal year
     * (T14 task 3), with the opening balance computed the same way
     * AccountingReportController's Cash/Bank Book does it - that year's
     * Opening Balance voucher lines plus that year's own lines dated before
     * `from` - so a window that does not start on day one still opens at the
     * right figure instead of at zero (audit-class bug: P0-18's "opening
     * balance double-counts" applies to any windowed book, not only Cash
     * Book). Each line also carries a `document` drill-down to whichever
     * module record (if any) the voucher was posted on behalf of.
     */
    public function ledger(Request $request, Account $account): Response
    {
        $fiscalYearId = $request->integer('fiscal_year_id') ?: FiscalYear::query()->where('status', FiscalYearStatus::Open)->value('id');
        $fiscalYear = $fiscalYearId ? FiscalYear::find($fiscalYearId) : null;

        $data = $fiscalYear
            ? $this->ledgerData($account, $fiscalYear, $request->string('from')->toString(), $request->string('to')->toString())
            : $this->emptyLedgerData();

        return Inertia::render('Tenant/Accounting/Accounts/Ledger', array_merge([
            'account' => $account->only(['id', 'code', 'name']),
            'fiscalYears' => FiscalYear::query()->orderByDesc('start_date')->get(['id', 'name', 'status', 'start_date', 'end_date']),
            'fiscalYearId' => $fiscalYearId,
        ], $data));
    }

    public function ledgerPrint(Request $request, Account $account)
    {
        $fiscalYear = $this->resolveLedgerFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to print.');

        $data = $this->ledgerData($account, $fiscalYear, $request->string('from')->toString(), $request->string('to')->toString());

        $pdf = Pdf::loadView('pdf.account-book', [
            'title' => "Ledger - {$account->name}",
            'company' => CompanySetting::current(),
            'documentNumber' => $account->code ? "{$account->code} - {$account->name}" : $account->name,
            'documentDate' => $data['to'],
            'dateAd' => $data['to'],
            'dateBs' => NepaliCalendar::formatBs($data['to']),
            'fiscalYearName' => $fiscalYear->name,
            'from' => $data['from'],
            'to' => $data['to'],
            'openingBalance' => $data['openingBalance'],
            'closingBalance' => $data['closingBalance'],
            'entries' => $data['entries'],
        ]);

        return $pdf->stream("ledger-{$account->id}.pdf");
    }

    public function ledgerExport(Request $request, Account $account)
    {
        $fiscalYear = $this->resolveLedgerFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to export.');

        $data = $this->ledgerData($account, $fiscalYear, $request->string('from')->toString(), $request->string('to')->toString());

        return Excel::download(
            new AccountBookExport($data['entries'], $data['openingBalance'], $data['closingBalance']),
            "ledger-{$account->id}.xlsx",
        );
    }

    private function resolveLedgerFiscalYear(Request $request): ?FiscalYear
    {
        $fiscalYearId = $request->integer('fiscal_year_id') ?: FiscalYear::query()->where('status', FiscalYearStatus::Open)->value('id');

        return $fiscalYearId ? FiscalYear::find($fiscalYearId) : null;
    }

    /**
     * @return array{entries: array<int, array<string, mixed>>, openingBalance: string, closingBalance: string, from: string, to: string}
     */
    private function ledgerData(Account $account, FiscalYear $fiscalYear, string $requestedFrom, string $requestedTo): array
    {
        $start = $fiscalYear->start_date->toDateString();
        $end = $fiscalYear->end_date->toDateString();

        $from = $requestedFrom !== '' ? max($requestedFrom, $start) : $start;
        $to = $requestedTo !== '' ? min($requestedTo, $end) : $end;

        if ($from > $to) {
            [$from, $to] = [$start, $end];
        }

        $openingBalance = $this->openingBalanceFor($account, $fiscalYear, $from);

        $lines = $account->journalVoucherLines()
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

        // Money, not a float accumulator: a running balance summed in floats
        // drifts a paisa at a time down a long ledger, and a balance that
        // lands on exactly zero used to render as "-0.00" whenever the last
        // subtraction happened to produce negative zero. Money's toString()
        // is exact and prints plain "0.00". Amounts leave as strings so the
        // page formats them with formatMoney() rather than re-deriving them
        // from a JSON number.
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
                'document' => $this->documentFor($line->journalVoucher),
            ];
        })->values()->all();

        return [
            'entries' => $entries,
            'openingBalance' => $openingBalance->toString(),
            'closingBalance' => $runningBalance->toString(),
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * @return array{entries: array<int, array<string, mixed>>, openingBalance: string, closingBalance: string, from: null, to: null}
     */
    private function emptyLedgerData(): array
    {
        return ['entries' => [], 'openingBalance' => '0.00', 'closingBalance' => '0.00', 'from' => null, 'to' => null];
    }

    /**
     * This year's Opening Balance voucher lines on $account, plus this
     * year's own lines dated before $from - the same "never sum across a
     * year boundary" rule AccountingReportController::accountBook() uses for
     * the Cash/Bank Book (audit P0-18).
     */
    private function openingBalanceFor(Account $account, FiscalYear $fiscalYear, string $from): Money
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

        return Money::of(BigDecimal::of((int) $openingScaled)->dividedBy(100, 2, RoundingMode::Unnecessary));
    }

    /**
     * The module record (if any) that posted $voucher, for the ledger's
     * per-line drill-down (T14 task 3): a bill number plus, where the module
     * has its own print page, a link straight to it. Returns null for a
     * standalone Journal/cash-bank voucher and for any system-bookkeeping
     * voucher (Opening Balance, Closing Entry, Roll Forward) - none of those
     * has a "source document" beyond the voucher itself.
     *
     * One query per owning table, same shape as
     * JournalVoucher::sourceRecordLabel() - a report is not a hot path, so
     * clarity wins over folding this into a single query.
     *
     * @return array{label: string, url: ?string}|null
     */
    private function documentFor(JournalVoucher $voucher): ?array
    {
        $lookups = [
            [Sale::class, 'journal_voucher_id', fn (Sale $m) => [
                'label' => 'Sale '.($m->invoice_number ?? "#{$m->id}"),
                'url' => route('tenant.sales.print', $m),
            ]],
            [Purchase::class, 'journal_voucher_id', fn (Purchase $m) => [
                'label' => 'Purchase '.($m->bill_number ?: "#{$m->id}"),
                'url' => route('tenant.purchases.print', $m),
            ]],
            [SalesReturn::class, 'journal_voucher_id', fn (SalesReturn $m) => [
                'label' => $m->documentNumber(),
                'url' => route('tenant.sales-returns.print', $m),
            ]],
            [PurchaseReturn::class, 'journal_voucher_id', fn (PurchaseReturn $m) => [
                'label' => $m->documentNumber(),
                'url' => route('tenant.purchase-returns.print', $m),
            ]],
            [CapitalSale::class, 'journal_voucher_id', fn (CapitalSale $m) => [
                'label' => 'Capital Sale '.$m->documentNumber(),
                'url' => route('tenant.capital-sales.print', $m),
            ]],
            [CapitalPurchase::class, 'journal_voucher_id', fn (CapitalPurchase $m) => [
                'label' => 'Capital Purchase '.($m->bill_number ?: "#{$m->id}"),
                'url' => null,
            ]],
            [Receipt::class, 'journal_voucher_id', fn (Receipt $m) => ['label' => "Receipt #{$m->id}", 'url' => null]],
            [Payment::class, 'journal_voucher_id', fn (Payment $m) => ['label' => "Payment #{$m->id}", 'url' => null]],
            [FixedAsset::class, 'journal_voucher_id', fn (FixedAsset $m) => ['label' => "Fixed Asset {$m->asset_code}", 'url' => null]],
            [FixedAsset::class, 'disposal_journal_voucher_id', fn (FixedAsset $m) => ['label' => "Fixed Asset disposal {$m->asset_code}", 'url' => null]],
            [FixedAssetDepreciation::class, 'journal_voucher_id', fn (FixedAssetDepreciation $m) => [
                'label' => 'Depreciation '.($m->fixedAsset?->asset_code ?? "#{$m->fixed_asset_id}"),
                'url' => null,
            ]],
        ];

        foreach ($lookups as [$modelClass, $column, $resolve]) {
            /** @var Model|null $record */
            $record = $modelClass::query()->where($column, $voucher->id)->first();

            if ($record) {
                return $resolve($record);
            }
        }

        return null;
    }

    /**
     * Downloads a blank CSV template for the opening-balance bulk import,
     * plus two example rows that balance (a real import's debit/credit
     * totals must match exactly - see importOpeningBalances()).
     */
    public function openingBalanceTemplate(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::OPENING_BALANCE_IMPORT_COLUMNS);
            fputcsv($handle, ['AS1', '', '5000.00', '']);
            fputcsv($handle, ['LIA20', '', '', '5000.00']);
            fclose($handle);
        }, 'opening-balance-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Bulk-sets opening balances by posting every row of the CSV as one new
     * Journal Voucher (voucher_type=opening_balance) via the exact same
     * JournalVoucher::post() the manual create form uses - not a parallel
     * ledger-posting path. Posts into whichever fiscal year is currently
     * open (this import doesn't expose the closed-year-correction picker
     * Journal Voucher/Purchase/Stock Adjustment's create forms do - opening
     * balances are overwhelmingly a one-time, current-year setup action, and
     * nothing today needs the reopened-for-correction override for this
     * specific flow; add it if a real need surfaces).
     *
     * Unlike Customer/Supplier/Item/Opening-Stock import, this one is
     * all-or-nothing: a journal voucher's lines must balance as a whole
     * (JournalVoucher::validateLines()), so silently dropping an invalid row
     * could turn a balanced file into an unbalanced voucher. Every row is
     * validated first; if any row fails, nothing is posted at all and every
     * failing row is reported together, exactly like a normal skip-list but
     * with imported always 0 in that case.
     */
    public function importOpeningBalances(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
            'date' => ['required', 'date'],
        ]);

        $rows = $this->parseCsvRows($request->file('file'));

        if ($rows === null || (! array_key_exists('debit', $rows[0]) && ! array_key_exists('credit', $rows[0]))) {
            return back()->withErrors(['file' => 'That file could not be read. Make sure it matches the downloaded template and has "debit"/"credit" columns.'])->withInput();
        }

        $accountsByCode = Account::query()->whereNotNull('code')->get(['id', 'code'])->keyBy(fn (Account $account) => strtolower($account->code));
        $accountsByName = Account::all(['id', 'name'])->groupBy(fn (Account $account) => strtolower($account->name));

        $seenAccounts = [];
        $skipped = [];
        $lines = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +1 for the 0-based index, +1 for the header row.
            $code = trim($row['code'] ?? '');
            $name = trim($row['name'] ?? '');
            $label = $code !== '' ? $code : $name;

            $account = null;

            if ($code !== '') {
                $account = $accountsByCode->get(strtolower($code));

                if (! $account) {
                    $skipped[] = ['row' => $rowNumber, 'name' => $label, 'reason' => "Unknown account code \"{$code}\"."];

                    continue;
                }
            } elseif ($name !== '') {
                $matches = $accountsByName->get(strtolower($name));

                if (! $matches || $matches->count() === 0) {
                    $skipped[] = ['row' => $rowNumber, 'name' => $label, 'reason' => "Unknown account \"{$name}\"."];

                    continue;
                }

                if ($matches->count() > 1) {
                    $skipped[] = ['row' => $rowNumber, 'name' => $label, 'reason' => "\"{$name}\" matches more than one account - use its code instead."];

                    continue;
                }

                $account = $matches->first();
            } else {
                $skipped[] = ['row' => $rowNumber, 'name' => $label, 'reason' => 'An account code or name is required.'];

                continue;
            }

            if (isset($seenAccounts[$account->id])) {
                $skipped[] = ['row' => $rowNumber, 'name' => $label, 'reason' => 'Duplicate account already used earlier in this file.'];

                continue;
            }

            if (in_array(strtoupper((string) $account->code), self::OPENING_BALANCE_BLOCKED_CODES, true)) {
                $skipped[] = ['row' => $rowNumber, 'name' => $label, 'reason' => "\"{$account->name}\" is valued by the opening stock import, not here."];

                continue;
            }

            if ($account->isProfitAndLoss()) {
                $skipped[] = ['row' => $rowNumber, 'name' => $label, 'reason' => "\"{$account->name}\" is an income or expense account, which has no opening balance."];

                continue;
            }

            $validator = Validator::make([
                'debit' => $row['debit'] ?? '',
                'credit' => $row['credit'] ?? '',
            ], [
                // decimal:0,2 as well as numeric: a rupee amount carrying a
                // third decimal used to reach MySQL unrounded and be stored
                // per line at whatever it rounded to, which is exactly how a
                // file that balanced on paper posted an unbalanced voucher
                // (audit P0-2). Rejected with the row number instead.
                'debit' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
                'credit' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            ]);

            if ($validator->fails()) {
                $skipped[] = ['row' => $rowNumber, 'name' => $label, 'reason' => $validator->errors()->first()];

                continue;
            }

            $debit = Money::ofNullable(trim((string) ($row['debit'] ?? ''))) ?? Money::zero();
            $credit = Money::ofNullable(trim((string) ($row['credit'] ?? ''))) ?? Money::zero();

            if ($debit->isPositive() === $credit->isPositive()) {
                $skipped[] = ['row' => $rowNumber, 'name' => $label, 'reason' => 'Exactly one of debit or credit must be greater than zero.'];

                continue;
            }

            $seenAccounts[$account->id] = true;

            $lines[] = [
                'account_id' => $account->id,
                'debit' => $debit->toString(),
                'credit' => $credit->toString(),
                'narration' => self::OPENING_BALANCE_IMPORT_NARRATION,
            ];
        }

        if ($skipped !== []) {
            return redirect()->route('tenant.accounts.index')
                ->with('status', 'No opening balances were imported - fix the row(s) below and re-upload.')
                ->with('importResult', ['imported' => 0, 'skipped' => $skipped]);
        }

        if (count($lines) < 2) {
            return back()->withErrors(['file' => 'At least two account rows are required.'])->withInput();
        }

        try {
            DB::transaction(function () use ($data, $lines, $request): void {
                $this->reverseActiveOpeningBalanceImports($request->user(), 'Replaced by a new opening balance import');

                JournalVoucher::post(
                    [
                        'voucher_type' => VoucherType::OpeningBalance->value,
                        'date' => $data['date'],
                        'narration' => self::OPENING_BALANCE_IMPORT_NARRATION,
                    ],
                    $lines,
                    $request->user(),
                );
            });
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['file' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.accounts.index')
            ->with('status', 'Imported opening balances for '.count($lines).' account(s).')
            ->with('importResult', ['imported' => count($lines), 'skipped' => []]);
    }

    /**
     * Clears one opening-balance import: posts its mirror through
     * JournalVoucher::reverse() and marks it cancelled, leaving both the
     * import and its reversal on the ledger.
     *
     * Restricted to import-created vouchers (see
     * OPENING_BALANCE_IMPORT_NARRATION) so the year-end carry-forward's own
     * OpeningBalance voucher can never be undone from this screen.
     */
    public function reverseOpeningBalanceImport(Request $request, JournalVoucher $journalVoucher): RedirectResponse
    {
        if (! $this->isOpeningBalanceImport($journalVoucher)) {
            return back()->withErrors(['opening_balance_import' => 'Only an opening balance import can be cleared here.']);
        }

        try {
            JournalVoucher::reverse($journalVoucher, $request->user(), 'Opening balance import cleared');
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['opening_balance_import' => $e->getMessage()]);
        }

        return redirect()->route('tenant.accounts.index')->with('status', 'Opening balance import cleared.');
    }

    /**
     * Reverses every still-posted opening-balance import in the open fiscal
     * year, so a re-import replaces the previous one instead of stacking on
     * top of it - importing the same file twice used to double every opening
     * balance with no way back (audit P1, "Opening balance import stacks,
     * cannot be reversed").
     *
     * Scoped to the open year on purpose: an import belonging to a year that
     * has since closed is that year's history, not something this year's
     * import replaces, and a reversal dated today could not post into it
     * anyway.
     *
     * Idempotent: a second call finds nothing left to reverse.
     */
    private function reverseActiveOpeningBalanceImports(User $actor, string $narration): void
    {
        $previous = JournalVoucher::query()
            ->where('voucher_type', VoucherType::OpeningBalance)
            ->where('narration', self::OPENING_BALANCE_IMPORT_NARRATION)
            ->where('status', 'posted')
            ->whereHas('fiscalYear', fn ($query) => $query->where('status', FiscalYearStatus::Open))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($previous as $voucher) {
            JournalVoucher::reverse($voucher, $actor, $narration);
        }
    }

    private function isOpeningBalanceImport(JournalVoucher $voucher): bool
    {
        return $voucher->voucher_type === VoucherType::OpeningBalance
            && $voucher->narration === self::OPENING_BALANCE_IMPORT_NARRATION;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Account $account = null): array
    {
        return $request->validate([
            'account_group_id' => ['nullable', 'required_without:account_subgroup_id', 'exists:account_groups,id'],
            'account_subgroup_id' => ['nullable', 'required_without:account_group_id', 'exists:account_subgroups,id'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('accounts')->ignore($account)],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
