<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Http\Controllers\Concerns\ImportsCsv;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AccountSubgroup;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
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

    public function index(): Response
    {
        return Inertia::render('Tenant/Accounting/Accounts/Index', [
            'accountGroups' => AccountGroup::query()->orderBy('name')->get(['id', 'name']),
            'accountSubgroups' => AccountSubgroup::query()->orderBy('name')->get(['id', 'account_group_id', 'name']),
            'accounts' => Account::query()->with(['group:id,name', 'subgroup:id,name'])->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Account::create($this->validated($request));

        return redirect()->route('tenant.accounts.index')->with('status', 'Account added.');
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $account->update($this->validated($request, $account));

        return redirect()->route('tenant.accounts.index')->with('status', 'Account updated.');
    }

    public function destroy(Account $account): RedirectResponse
    {
        $account->delete();

        return redirect()->route('tenant.accounts.index')->with('status', 'Account deleted.');
    }

    public function ledger(Request $request, Account $account): Response
    {
        $fiscalYearId = $request->integer('fiscal_year_id') ?: FiscalYear::query()->where('status', FiscalYearStatus::Open)->value('id');

        $entries = $account->journalVoucherLines()
            ->whereHas('journalVoucher', fn ($query) => $query->where('fiscal_year_id', $fiscalYearId))
            ->with('journalVoucher')
            ->get()
            ->sortBy([['journalVoucher.date', 'asc'], ['id', 'asc']])
            ->values();

        $runningBalance = 0.0;

        $entries = $entries->map(function (JournalVoucherLine $line) use (&$runningBalance) {
            $runningBalance += (float) $line->debit - (float) $line->credit;

            return [
                'date' => $line->journalVoucher->date->toDateString(),
                'voucherType' => $line->journalVoucher->voucher_type->value,
                'voucherNumber' => $line->journalVoucher->voucher_number,
                'narration' => $line->narration ?? $line->journalVoucher->narration,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'balance' => $runningBalance,
            ];
        });

        return Inertia::render('Tenant/Accounting/Accounts/Ledger', [
            'account' => $account->only(['id', 'code', 'name']),
            'fiscalYears' => FiscalYear::query()->orderByDesc('start_date')->get(['id', 'name', 'status']),
            'fiscalYearId' => $fiscalYearId,
            'entries' => $entries,
        ]);
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

            $validator = Validator::make([
                'debit' => $row['debit'] ?? '',
                'credit' => $row['credit'] ?? '',
            ], [
                'debit' => ['nullable', 'numeric', 'min:0'],
                'credit' => ['nullable', 'numeric', 'min:0'],
            ]);

            if ($validator->fails()) {
                $skipped[] = ['row' => $rowNumber, 'name' => $label, 'reason' => $validator->errors()->first()];

                continue;
            }

            $debit = (float) ($row['debit'] ?? 0);
            $credit = (float) ($row['credit'] ?? 0);

            if (($debit > 0) === ($credit > 0)) {
                $skipped[] = ['row' => $rowNumber, 'name' => $label, 'reason' => 'Exactly one of debit or credit must be greater than zero.'];

                continue;
            }

            $seenAccounts[$account->id] = true;

            $lines[] = [
                'account_id' => $account->id,
                'debit' => $debit,
                'credit' => $credit,
                'narration' => 'Opening balance import',
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
            JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::OpeningBalance->value,
                    'date' => $data['date'],
                    'narration' => 'Opening balance import',
                ],
                $lines,
                $request->user(),
            );
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['file' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.accounts.index')
            ->with('status', 'Imported opening balances for '.count($lines).' account(s).')
            ->with('importResult', ['imported' => count($lines), 'skipped' => []]);
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
