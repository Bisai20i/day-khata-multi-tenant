<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Exports\LedgerExport;
use App\Exports\Samples\OpeningBalanceSampleExport;
use App\Http\Controllers\Concerns\ImportsCsv;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AccountSubgroup;
use App\Models\ActivityLog;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchContext;
use App\Support\Branches\BranchFilter;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use App\Support\Reports\LedgerBook;
use App\Support\Reports\LedgerStatement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
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
    /**
     * Stock accounts valued by the opening stock import rather than here:
     * trading Stock in Hand plus the perpetual inventory control accounts
     * (ManufacturingAccounts::CONTROL_CODES), whose balance must equal the
     * stock ledger. Also the HR control accounts LIA22 (Salary Payable) and
     * ASA24 (Staff Advances Receivable), which must equal the HR sub-ledger.
     * And ASA25 (Inter-Branch Clearing, BranchAccounts): only the system
     * posts it, in matching pairs across branches, so a one-sided opening
     * balance would break invariant I3 and block the year-end close.
     * Frozen as literals like the rest of this list.
     */
    private const OPENING_BALANCE_BLOCKED_CODES = ['AS11', 'AS12', 'AS13', 'AS14', 'LIA22', 'ASA24', 'ASA25'];

    /**
     * The chart of accounts. The three inter-branch control accounts
     * (BranchAccounts::CONTROL_CODES, seeded for every tenant) mean nothing
     * to a company that is not multi-branch, so they are listed only once it
     * is, or once any voucher line has ever touched them (a company that
     * traded across branches and went back to Main alone keeps seeing the
     * balances it carries). BR22 parity; the account pickers are untouched.
     */
    public function index(Request $request): Response
    {
        $showBranchControlAccounts = ActiveBranchSelection::isMultiBranch($request);

        return Inertia::render('Tenant/Accounting/Accounts/Index', [
            'accountGroups' => AccountGroup::query()->with('accountHead:id,name')->orderBy('name')->get(['id', 'account_head_id', 'name']),
            'accountSubgroups' => AccountSubgroup::query()->with(['accountGroup.accountHead:id,name', 'accountGroup:id,account_head_id,name'])->orderBy('name')->get(['id', 'account_group_id', 'name']),
            'parentAccounts' => Account::query()->orderBy('name')->get(['id', 'name', 'code']),
            'accounts' => Account::query()
                ->with([
                    'group.accountHead:id,name',
                    'subgroup.accountGroup.accountHead:id,name',
                    'subgroup.accountGroup:id,account_head_id,name',
                    'parent:id,name,code',
                ])
                ->when(! $showBranchControlAccounts, fn (Builder $query) => $query->where(
                    function (Builder $inner): void {
                        $inner->whereNull('code')
                            ->orWhereNotIn('code', BranchAccounts::CONTROL_CODES)
                            ->orWhereHas('journalVoucherLines');

                        // Defence in depth: an account holding a control code
                        // under another name is not a control account
                        // (BranchAccounts::codeConflictMessage()), so it stays
                        // listed. The migration moves any such clash aside;
                        // this only covers one introduced outside the app
                        // (e.g. a direct database edit).
                        foreach (BranchAccounts::CONTROL_NAMES as $code => $name) {
                            $inner->orWhere(fn (Builder $own) => $own->where('code', $code)->where('name', '!=', $name));
                        }
                    },
                ))
                ->orderBy('name')
                ->get(),
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
        // The usage check is re-run under a row lock inside the transaction
        // so a posting that lands between the click and the delete cannot
        // slip through; the model's deleting hook is the final backstop.
        try {
            DB::transaction(function () use ($account): void {
                $locked = Account::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

                if ($blocker = $locked->deletionBlocker()) {
                    throw new InvalidArgumentException($blocker);
                }

                $locked->delete();

                ActivityLog::create([
                    'user_id' => auth('web')->id(),
                    'action' => 'deleted',
                    'subject_type' => $locked->getMorphClass(),
                    'subject_id' => $locked->getKey(),
                    'description' => sprintf('Account #%s "%s" deleted', $locked->getKey(), $locked->name),
                    'changes' => null,
                ]);
            });
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['account' => $e->getMessage()]);
        }

        return redirect()->route('tenant.accounts.index')->with('status', 'Account deleted.');
    }

    /**
     * The account ledger: an arbitrary date window inside one fiscal year
     * (T14 task 3), built by the shared LedgerBook (the general ledger
     * report's engine) with the Trial Balance's semantics: the opening
     * balance is that year's Opening Balance voucher lines plus that year's
     * own lines dated before `from`, the window excludes Opening Balance
     * vouchers, and both leave out the profit-and-loss sweep voucher - so a
     * window that does not start on day one still opens at the right figure,
     * and a closed year's P&L account agrees with the Trial Balance. Each
     * line carries a `reference` and a `document` drill-down to whichever
     * module record (if any) the voucher was posted on behalf of; the
     * response also carries openingSide/closingSide (Dr, Cr or '') and the
     * period debit/credit totals for the table's Total row.
     *
     * Branches (plan 12, BR14): the same BranchFilter as the general ledger
     * report (`branch`, `branches[]`), resolved the same way. An explicit
     * choice wins (`branch=all` is "every branch I may see"); with none the
     * statement follows the top-bar switcher only when it is narrowed to a
     * branch, and with the switcher on "All branches" it stays company-wide
     * (a restricted user still only ever sees their own branches' lines,
     * labelled as such). The account itself is one company account; only
     * its lines are narrowed, opening balance included, so the running
     * balance is the branch's own.
     */
    public function ledger(Request $request, Account $account): Response
    {
        $this->authorizeLedgerAccess($request, $account, 'view');
        $this->validateLedgerInput($request);

        $fiscalYear = $this->resolveLedgerFiscalYear($request);
        $fiscalYearId = $fiscalYear?->id;
        $branchFilter = BranchFilter::fromRequest($request);

        $data = $fiscalYear
            ? $this->ledgerBook($request, $account, $fiscalYear, $branchFilter)
            : $this->emptyLedgerData();

        return Inertia::render('Tenant/Accounting/Accounts/Ledger', array_merge([
            'account' => $account->only(['id', 'code', 'name']),
            'partyArea' => $this->partyAreaName($account),
            // Per account, so a party-ledger-only user sees Print/Export on a
            // customer statement exactly when the routes would allow it.
            'canPrint' => $this->mayOpenLedger($request->user(), $account, 'print'),
            'canExport' => $this->mayOpenLedger($request->user(), $account, 'export'),
            'fiscalYears' => FiscalYear::query()->orderByDesc('start_date')->get(['id', 'name', 'status', 'start_date', 'end_date']),
            'fiscalYearId' => $fiscalYearId,
            'branchReport' => $branchFilter->payload(),
        ], $data));
    }

    /**
     * The statement as a PDF, rendered by the dedicated pdf.ledger view from
     * the same book the page shows (opening row, entries, Total row, closing
     * row, Dr/Cr on every balance).
     */
    public function ledgerPrint(Request $request, Account $account)
    {
        $this->authorizeLedgerAccess($request, $account, 'print');
        $this->validateLedgerInput($request);

        $fiscalYear = $this->resolveLedgerFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to print.');

        $branchFilter = BranchFilter::fromRequest($request);
        $data = $this->ledgerBook($request, $account, $fiscalYear, $branchFilter);

        $pdf = Pdf::loadView('pdf.ledger', [
            'title' => "Ledger - {$account->name}",
            'company' => CompanySetting::current(),
            'documentNumber' => $account->code ? "{$account->code} - {$account->name}" : $account->name,
            'partyArea' => $this->partyAreaName($account),
            'documentDate' => $data['to'],
            'dateAd' => $data['to'],
            'dateBs' => NepaliCalendar::formatBs($data['to']),
            'fiscalYearName' => $fiscalYear->name,
            'from' => $data['from'],
            'to' => $data['to'],
            // Printed from BR16 on; null for a tenant that is not multi-branch.
            'branchFilterLabel' => $branchFilter->isActive() ? $branchFilter->label() : null,
            'includesSubLedgers' => false,
            'openingBalance' => $data['openingBalance'],
            'openingSide' => $data['openingSide'],
            'closingBalance' => $data['closingBalance'],
            'closingSide' => $data['closingSide'],
            'periodDebit' => $data['periodDebit'],
            'periodCredit' => $data['periodCredit'],
            'entries' => $data['entries'],
        ]);

        return $pdf->stream(LedgerStatement::fileName($account, $fiscalYear, 'pdf'));
    }

    /**
     * The statement as an Excel file, mirroring the page and the PDF.
     */
    public function ledgerExport(Request $request, Account $account)
    {
        $this->authorizeLedgerAccess($request, $account, 'export');
        $this->validateLedgerInput($request);

        $fiscalYear = $this->resolveLedgerFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to export.');

        $branchFilter = BranchFilter::fromRequest($request);
        $data = $this->ledgerBook($request, $account, $fiscalYear, $branchFilter);

        return Excel::download(
            new LedgerExport(
                entries: $data['entries'],
                openingBalance: $data['openingBalance'],
                openingSide: $data['openingSide'],
                closingBalance: $data['closingBalance'],
                closingSide: $data['closingSide'],
                periodDebit: $data['periodDebit'],
                periodCredit: $data['periodCredit'],
                branchLabel: $branchFilter->isActive() ? $branchFilter->label() : null,
                accountLabel: $account->code ? "{$account->code} - {$account->name}" : $account->name,
                periodLabel: "{$data['from']} to {$data['to']} - {$fiscalYear->name}",
            ),
            LedgerStatement::fileName($account, $fiscalYear, 'xlsx'),
        );
    }

    /**
     * The three ledger routes carry no `can:` middleware (ROUTE-MAP shared
     * lookup item 1), so this is their only gate. account_ledger.$action
     * opens any account (accounting module). party_ledger.$action (core
     * module, so it survives the accounting module being switched off) opens
     * only an account that backs a customer or supplier: a statement for a
     * party the user already deals with, never the cash, bank, capital or
     * income accounts. Agent accounts are deliberately not party accounts
     * (agents are their own module). Checked before validation so an
     * unauthorized caller learns nothing from validation errors.
     *
     * @param  'view'|'print'|'export'  $action
     */
    private function authorizeLedgerAccess(Request $request, Account $account, string $action): void
    {
        abort_unless($this->mayOpenLedger($request->user(), $account, $action), 403);
    }

    /**
     * @param  'view'|'print'|'export'  $action
     */
    private function mayOpenLedger(?User $user, Account $account, string $action): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->can("account_ledger.{$action}")) {
            return true;
        }

        return $user->can("party_ledger.{$action}") && $this->isPartyAccount($account);
    }

    /**
     * Whether the account is the ledger account of a customer or supplier.
     */
    private function isPartyAccount(Account $account): bool
    {
        return Customer::query()->where('account_id', $account->id)->exists()
            || Supplier::query()->where('account_id', $account->id)->exists();
    }

    /**
     * The area of the customer or supplier this account is the ledger of, for
     * the statement header; null for any other account or a party with no area.
     */
    private function partyAreaName(Account $account): ?string
    {
        $party = Customer::query()->with('area:id,name')->where('account_id', $account->id)->first()
            ?? Supplier::query()->with('area:id,name')->where('account_id', $account->id)->first();

        return $party?->area?->name;
    }

    /**
     * A year that does not exist, or a date window that runs backwards, is a
     * validation error rather than a silent fallback to the open year
     * (flags G-21).
     */
    private function validateLedgerInput(Request $request): void
    {
        $request->validate([
            'fiscal_year_id' => ['nullable', 'integer', 'exists:fiscal_years,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
    }

    private function resolveLedgerFiscalYear(Request $request): ?FiscalYear
    {
        $requestedId = $request->integer('fiscal_year_id');

        if ($requestedId) {
            return FiscalYear::find($requestedId);
        }

        return FiscalYear::query()->where('status', FiscalYearStatus::Open)->first()
            ?? FiscalYear::query()->orderByDesc('start_date')->first();
    }

    /**
     * The statement for one account over one window of one fiscal year, built
     * by the same LedgerBook the general ledger report uses (opening balance,
     * period lines, running balance, per-line source-document drill-down), so
     * this page, its PDF and its Excel can never disagree with the report.
     *
     * The window is clamped inside the year by LedgerStatement::window().
     * Sub-account roll-up stays off here: this is the statement of exactly
     * this account. $filter->lineBranchIds() narrows both the opening balance
     * and the window's lines (plan 12, section 8); null adds nothing.
     *
     * @return array{entries: list<array<string, mixed>>, openingBalance: string, openingSide: string, closingBalance: string, closingSide: string, periodDebit: string, periodCredit: string, from: string, to: string}
     */
    private function ledgerBook(Request $request, Account $account, FiscalYear $fiscalYear, BranchFilter $filter): array
    {
        [$from, $to] = LedgerStatement::window(
            $fiscalYear,
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
        );

        // Particulars name the opposite accounts of each voucher; a party-ledger
        // viewer without account_ledger.view keeps the document links only.
        $withParticulars = (bool) $request->user()?->can('account_ledger.view');

        $book = LedgerBook::build($account, $fiscalYear, $from, $to, $filter->lineBranchIds(), false, true, null, $withParticulars);

        return array_merge($book, ['from' => $from, 'to' => $to]);
    }

    /**
     * Same keys as ledgerBook(), for a company with no fiscal year at all.
     *
     * @return array{entries: list<array<string, mixed>>, openingBalance: string, openingSide: string, closingBalance: string, closingSide: string, periodDebit: string, periodCredit: string, from: null, to: null}
     */
    private function emptyLedgerData(): array
    {
        return [
            'entries' => [],
            'openingBalance' => '0.00',
            'openingSide' => '',
            'closingBalance' => '0.00',
            'closingSide' => '',
            'periodDebit' => '0.00',
            'periodCredit' => '0.00',
            'from' => null,
            'to' => null,
        ];
    }

    /**
     * Downloads a blank CSV template for the opening-balance bulk import,
     * plus two example rows that balance (a real import's debit/credit
     * totals must match exactly - see importOpeningBalances()).
     */
    public function openingBalanceSampleExcel()
    {
        return Excel::download(new OpeningBalanceSampleExport, 'opening-balance-import-sample.xlsx');
    }

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
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls'],
            'date' => ['required', 'date'],
        ]);

        $rows = $this->parseCsvRows($request->file('file'));

        if ($rows === null || (! array_key_exists('debit', $rows[0]) && ! array_key_exists('credit', $rows[0]))) {
            return back()->withErrors(['file' => 'That file could not be read. Make sure it matches the downloaded template and has "debit"/"credit" columns.'])->withInput();
        }

        // The checks below read each account's name and, through its group,
        // whether it is a profit-and-loss account; loading only id and code
        // left both blank, so a P&L account slipped through as an opening
        // balance and the stock-account message named no account.
        $accounts = Account::query()
            ->with(['group.accountHead', 'subgroup.accountGroup.accountHead'])
            ->get(['id', 'code', 'name', 'account_group_id', 'account_subgroup_id']);
        $accountsByCode = $accounts->filter(fn (Account $account) => $account->code !== null)->keyBy(fn (Account $account) => strtolower($account->code));
        $accountsByName = $accounts->groupBy(fn (Account $account) => strtolower($account->name));

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
                $blockedCode = strtoupper((string) $account->code);
                $skipped[] = ['row' => $rowNumber, 'name' => $label, 'reason' => match (true) {
                    in_array($blockedCode, ['LIA22', 'ASA24'], true) => "\"{$account->name}\" is controlled by the HR module and cannot take an opening balance here.",
                    $blockedCode === 'ASA25' => "\"{$account->name}\" is posted only by the system for inter-branch entries and cannot take an opening balance here.",
                    default => "\"{$account->name}\" is valued by the opening stock import, not here.",
                }];

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
        // Looked up outside the branch access scope: an import replaces every
        // active import company-wide. reverse() still checks that the actor
        // may post into each voucher's branches.
        $previous = BranchContext::unrestricted(fn () => JournalVoucher::query()
            ->where('voucher_type', VoucherType::OpeningBalance)
            ->where('narration', self::OPENING_BALANCE_IMPORT_NARRATION)
            ->where('status', 'posted')
            ->whereHas('fiscalYear', fn ($query) => $query->where('status', FiscalYearStatus::Open))
            ->orderBy('id')
            ->lockForUpdate()
            ->get());

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
        $disallowedParents = $account ? array_merge([$account->id], $account->allSubAccountIds()) : [];

        $data = $request->validate([
            'parent_type' => ['nullable', 'string', 'in:group,subgroup,account'],
            'parent_account_id' => [
                'nullable',
                'required_if:parent_type,account',
                'required_without_all:account_group_id,account_subgroup_id',
                'integer',
                'exists:accounts,id',
                Rule::notIn($disallowedParents),
            ],
            'account_group_id' => ['nullable', 'required_without_all:account_subgroup_id,parent_account_id', 'exists:account_groups,id'],
            'account_subgroup_id' => ['nullable', 'required_without_all:account_group_id,parent_account_id', 'exists:account_subgroups,id'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('accounts')->ignore($account)],
            'name' => ['required', 'string', 'max:255'],
            'name_np' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
        ], [
            'parent_account_id.required_if' => 'Please select a parent account.',
            'parent_account_id.required_without_all' => 'Please select a parent account, group, or subgroup.',
            'parent_account_id.not_in' => 'An account cannot have itself or one of its sub-accounts as parent.',
            'account_group_id.required_without_all' => 'Please select an account group.',
            'account_subgroup_id.required_without_all' => 'Please select an account subgroup.',
        ]);

        if (! empty($data['parent_account_id']) && empty($data['account_group_id']) && empty($data['account_subgroup_id'])) {
            $parent = Account::query()->find($data['parent_account_id']);
            if ($parent) {
                $data['account_group_id'] = $parent->account_group_id;
                $data['account_subgroup_id'] = $parent->account_subgroup_id;
            }
        }

        unset($data['parent_type']);

        return $data;
    }
}
