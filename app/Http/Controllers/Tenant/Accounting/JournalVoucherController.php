<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Enums\VoucherType;
use App\Exports\JournalVoucherListExport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\PrintLog;
use App\Models\User;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchContext;
use App\Support\Branches\BranchFormOptions;
use App\Support\Branches\BranchListFilter;
use App\Support\Branches\BranchRuleViolation;
use App\Support\Branches\DocumentBranch;
use App\Support\Hr\HrAccounts;
use App\Support\Manufacturing\ManufacturingAccounts;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;

class JournalVoucherController extends Controller
{
    /**
     * Columns the list may be sorted by, keyed by the `sort` query value - an
     * allow-list so a crafted value never reaches orderBy().
     *
     * @var array<string, string>
     */
    private const SORTABLE_COLUMNS = [
        'date' => 'date',
        'voucher_number' => 'voucher_number',
    ];

    /**
     * Listing is server-side filtered (date range + voucher type + voucher
     * number/narration search), sorted and paginated - the same shape
     * Sales\SaleController::index() uses, rather than loading every voucher
     * the ledger has ever posted into one client-side table.
     */
    public function index(Request $request): Response
    {
        $filters = $this->listFilters($request);

        return Inertia::render('Tenant/Accounting/JournalVouchers/Index', [
            'journalVouchers' => $this->filteredVouchersQuery($filters, ActiveBranchSelection::narrowedBranchId($request))
                ->with(['fiscalYear:id,name', 'creator:id,name', 'lines.account:id,code,name'])
                // The header (origin) branch, named only when several
                // branches can appear (plan 12, BR12).
                ->when(BranchListFilter::columnVisible($request), fn ($query) => $query->with('branch:id,name,code'))
                ->paginate(25)
                ->withQueryString()
                // Per-branch numbering (BR20): vouchers in a branch series carry
                // series_branch.series_code so the list prints "JV-KTM-12"; a
                // shared-series page runs no extra query and gains no key.
                ->tap(fn ($page) => JournalVoucher::loadSeriesBranches($page->getCollection())),
            'filters' => $filters,
            // The inter-branch control accounts are hidden from the manual and
            // cash/bank voucher pickers; store() and storeCashBank() refuse
            // them anyway (BranchAccounts).
            'accounts' => BranchAccounts::exclude(Account::query())->orderBy('name')->get(['id', 'code', 'name']),
            // The one closed year currently reopened for correction, if
            // any - lets Create.vue offer it as the only non-current
            // fiscal-year option, per the locked design decision (see
            // ClosedFiscalYearGuard's docblock). Replaces the previous
            // "every fiscal year, closed or not" picker, which allowed an
            // admin+reason override into any closed year regardless of
            // whether it had been deliberately reopened.
            'correctionFiscalYear' => FiscalYear::openForCorrection()?->only(['id', 'name', 'reopen_reason']),
            // Branch pickers of the manual journal (header default plus a
            // per-line override); empty unless the company is multi-branch.
            ...BranchFormOptions::props($request),
        ]);
    }

    /**
     * Export of the same filtered/sorted/searched set index() shows, every
     * row and never a paginated page's worth. `format=csv` streams a .csv
     * instead of the default .xlsx - Laravel Excel infers the writer from
     * the filename extension.
     */
    public function export(Request $request)
    {
        $filters = $this->listFilters($request);
        $extension = $request->query('format') === 'csv' ? 'csv' : 'xlsx';

        $rows = $this->filteredVouchersQuery($filters, ActiveBranchSelection::narrowedBranchId($request))
            ->with(['fiscalYear:id,name', 'creator:id,name'])
            ->withSum('lines as total_debit', 'debit')
            ->get()
            ->tap(fn ($vouchers) => JournalVoucher::loadSeriesBranches($vouchers))
            ->map(fn (JournalVoucher $voucher): array => [
                'date' => $voucher->date->format('Y-m-d'),
                'voucher_type' => $voucher->voucher_type->value,
                'voucher_number' => $voucher->displayNumber(),
                'narration' => $voucher->narration,
                'fiscal_year' => $voucher->fiscalYear?->name,
                'amount' => Money::round($voucher->total_debit ?? '0')->toString(),
                'created_by' => $voucher->creator?->name,
                'status' => ucfirst((string) $voucher->status),
            ]);

        return Excel::download(new JournalVoucherListExport($rows), "journal-vouchers.{$extension}");
    }

    /**
     * @return array{from: ?string, to: ?string, voucher_type: ?string, search: ?string, sort: string, sort_dir: string}
     */
    private function listFilters(Request $request): array
    {
        $sort = $request->string('sort')->toString();
        $sortDir = $request->string('sort_dir')->toString();
        $voucherType = VoucherType::tryFrom($request->string('voucher_type')->toString());

        return [
            'from' => $request->filled('from') ? $request->string('from')->toString() : null,
            'to' => $request->filled('to') ? $request->string('to')->toString() : null,
            'voucher_type' => $voucherType?->value,
            'search' => $request->filled('search') ? trim($request->string('search')->toString()) : null,
            'sort' => array_key_exists($sort, self::SORTABLE_COLUMNS) ? $sort : 'date',
            'sort_dir' => $sortDir === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * The search box takes either a voucher number as printed ("JV-12", or
     * just "12") or any part of the narration.
     *
     * $branchId is the top-bar switcher's branch (plan 12, BR12; null for
     * "All branches" or a company that is not multi-branch, which adds no
     * predicate). A voucher belongs to a branch when it originated there or
     * has a line there, the same test JournalVoucher's access scope applies
     * for a restricted user: a receipt filed in branch A that settles a
     * branch B invoice shows under both, not only under its header branch.
     *
     * @param  array{from: ?string, to: ?string, voucher_type: ?string, search: ?string, sort: string, sort_dir: string}  $filters
     * @return Builder<JournalVoucher>
     */
    private function filteredVouchersQuery(array $filters, ?int $branchId = null): Builder
    {
        return JournalVoucher::query()
            ->when($branchId, function (Builder $query, int $branchId): void {
                $voucherKey = $query->qualifyColumn('id');

                $query->where(function (Builder $inBranch) use ($branchId, $voucherKey): void {
                    $inBranch->where($inBranch->qualifyColumn('branch_id'), $branchId)
                        ->orWhereExists(fn ($lines) => $lines->selectRaw('1')
                            ->from('journal_voucher_lines')
                            ->whereColumn('journal_voucher_lines.journal_voucher_id', $voucherKey)
                            ->where('journal_voucher_lines.branch_id', $branchId));
                });
            })
            ->when($filters['from'], fn ($query, string $from) => $query->whereDate('date', '>=', $from))
            ->when($filters['to'], fn ($query, string $to) => $query->whereDate('date', '<=', $to))
            ->when($filters['voucher_type'], fn ($query, string $type) => $query->where('voucher_type', $type))
            ->when($filters['search'], function ($query, string $search) {
                $number = preg_replace('/\D/', '', $search);
                $series = $this->seriesSearch($search);

                $query->where(function ($query) use ($search, $number, $series) {
                    $query->where('narration', 'like', "%{$search}%");

                    if ($series !== null) {
                        // "JV-KTM-12" / "KTM-12" as printed in a per_branch
                        // year: that branch's series only (plan 12, BR20).
                        $query->orWhere(fn ($inSeries) => $inSeries
                            ->where('series_branch_id', $series['branch_id'])
                            ->where('voucher_number', $series['number']));
                    } elseif ($number !== '') {
                        $query->orWhere('voucher_number', (int) $number);
                    }
                });
            })
            ->orderBy(self::SORTABLE_COLUMNS[$filters['sort']], $filters['sort_dir'])
            ->orderByDesc('id');
    }

    /**
     * Reads a search term printed as `[PREFIX-]CODE-N` (the number of a
     * per_branch year's voucher, e.g. "JV-KTM-12" or "KTM-12") where CODE is
     * an existing branch's series code; null for anything else, which keeps
     * the plain digits-only number search.
     *
     * @return array{branch_id: int, number: int}|null
     */
    private function seriesSearch(string $search): ?array
    {
        if (! preg_match('/^(?:[A-Za-z]+-)?([A-Za-z0-9]+)-(\d+)$/', trim($search), $parts)) {
            return null;
        }

        // Only a company that numbers some year per branch has such numbers;
        // everyone else keeps the plain digits search exactly as before.
        if (! FiscalYear::query()->where('numbering_scope', FiscalYear::NUMBERING_PER_BRANCH)->exists()) {
            return null;
        }

        $branchId = Branch::query()->where('series_code', strtoupper($parts[1]))->value('id');

        return $branchId === null ? null : ['branch_id' => (int) $branchId, 'number' => (int) $parts[2]];
    }

    /**
     * Manual journal. Branches (plan 12, section 5.3 item 5, BR07): an
     * optional header branch_id (the origin; default the actor's posting
     * branch) and an optional branch_id per line (default the header's), each
     * checked active and accessible through DocumentBranch. The voucher is
     * posted with autoBalance, so a journal that moves a balance between
     * branches (cut-over, shared-cost allocation) gets its Inter-Branch
     * Clearing lines, hub = the header branch. A journal with every line in
     * one branch is posted exactly as before.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'fiscal_year_id' => ['nullable', 'exists:fiscal_years,id'],
            'reason' => ['nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'narration' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'lines' => ['required', 'array', 'min:2'],
            // One line per account and branch (flags G-20), like the
            // cash/bank form below. Per branch, so a cut-over journal can
            // move cash from one branch to another on the same account.
            'lines.*.account_id' => ['required', 'exists:accounts,id', $this->oneLinePerAccountAndBranch($request)],
            'lines.*.branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            // decimal:0,2 as well as numeric: an amount with a third decimal
            // used to pass this check, balance against another third-decimal
            // line, and then be rounded per line by MySQL into an unbalanced
            // voucher (audit P0-2). JournalVoucher::validateLines() refuses it
            // too; this is here so the user gets a field-level message.
            'lines.*.debit' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.JournalVoucher::MAX_LINE_AMOUNT],
            'lines.*.credit' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.JournalVoucher::MAX_LINE_AMOUNT],
            'lines.*.narration' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            ManufacturingAccounts::assertNotControlAccounts(array_column($data['lines'], 'account_id'));
            HrAccounts::assertNotControlAccounts(array_column($data['lines'], 'account_id'));
            BranchAccounts::assertNotControlAccounts(array_column($data['lines'], 'account_id'));

            $actor = $request->user();
            $header = [
                ...Arr::only($data, ['fiscal_year_id', 'reason', 'date', 'narration']),
                'branch_id' => $this->usableBranchId($actor, $data['branch_id'] ?? null, 'branch_id'),
            ];
            $lines = [];

            foreach ($data['lines'] as $index => $line) {
                $requested = $line['branch_id'] ?? null;

                $lines[] = [
                    ...$line,
                    'branch_id' => $requested === null || $requested === '' ? null : $this->usableBranchId($actor, $requested, "lines.{$index}.branch_id"),
                ];
            }

            $voucher = JournalVoucher::post($header, $lines, $actor, autoBalance: true);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        } catch (BranchRuleViolation $e) {
            // A missing Inter-Branch Clearing account is a setup problem the
            // user must see on the form, not a 500.
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.journal-vouchers.index')
            ->with('status', 'Journal voucher posted.')
            ->with('created', $this->createdPayload($voucher));
    }

    /**
     * DocumentBranch's answer for one branch of a manual journal (the header
     * or a line). A refused branch (inactive, not accessible, invalid, no
     * usable branch) becomes a field error on $field, so the form can put it
     * on the branch picker of the header or of that line (plan 12, BR12);
     * anything else propagates to the caller's `lines` error as before.
     *
     * @throws ValidationException
     * @throws InvalidArgumentException
     */
    private function usableBranchId(User $actor, mixed $requested, string $field): int
    {
        try {
            return DocumentBranch::forDocument($actor, $requested)->branchId;
        } catch (InvalidArgumentException $e) {
            if (BranchFormOptions::errorField($e, 'lines', $field) === $field) {
                throw ValidationException::withMessages([$field => $e->getMessage()]);
            }

            throw $e;
        }
    }

    /**
     * The `distinct` rule, keyed by (account, effective branch) instead of
     * account alone. The effective branch is the one the engine will post
     * the line into: its own branch, else the header's, else the actor's
     * posting branch (Main for a single-branch company), so a Main-tagged
     * line and an untagged line on the same account are caught. Ids compare
     * as integers ("7" equals "07"). Like `distinct`, every row of a
     * duplicated pair gets the error on lines.N.account_id, so a journal
     * without branches fails on exactly the rows it always did.
     */
    private function oneLinePerAccountAndBranch(Request $request): Closure
    {
        $id = fn (mixed $value): ?int => is_int($value) || (is_string($value) && ctype_digit(trim($value))) ? (int) $value : null;

        $headerBranch = $id($request->input('branch_id'));

        if ($headerBranch === null) {
            try {
                $headerBranch = BranchContext::postingBranchId($request->user());
            } catch (BranchRuleViolation) {
                $headerBranch = BranchContext::mainBranchIdOrNull();
            }
        }

        $keys = [];

        foreach ((array) $request->input('lines', []) as $index => $line) {
            if (is_array($line)) {
                $accountId = $id($line['account_id'] ?? null);
                $lineBranch = $id($line['branch_id'] ?? null) ?? $headerBranch;

                // An unreadable account id is left to the `exists` rule.
                if ($accountId !== null) {
                    $keys[$index] = $accountId.'|'.($lineBranch ?? '');
                }
            }
        }

        $counts = array_count_values($keys);

        return function (string $attribute, mixed $value, Closure $fail) use ($keys, $counts): void {
            $index = explode('.', $attribute)[1] ?? null;
            $key = $index !== null ? ($keys[$index] ?? null) : null;

            if ($key !== null && $counts[$key] > 1) {
                $fail('Each account may appear only once per branch on a journal voucher.');
            }
        };
    }

    /**
     * Posts one of the five plain cash/bank vouchers (T14): Cash Receipt,
     * Cash Payment, Bank Receipt, Bank Payment, Contra. Shares this same
     * controller and the journal-vouchers list/cancel routes with the manual
     * Journal voucher, since - like a Journal voucher - the posted row IS
     * the record (see JournalVoucher::postCashBank()).
     *
     * `lines.*.account_id` gets `distinct` per CONTRACTS C5: naming the same
     * account twice in one payload must not let its amount silently
     * aggregate in a way the user did not see on screen.
     */
    public function storeCashBank(Request $request): RedirectResponse
    {
        $cashBankTypes = array_map(fn (VoucherType $type) => $type->value, VoucherType::cashBankTypes());

        $data = $request->validate([
            'voucher_type' => ['required', Rule::in($cashBankTypes)],
            'fiscal_year_id' => ['nullable', 'exists:fiscal_years,id'],
            'reason' => ['nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'narration' => ['required', 'string', 'max:255'],
            'bank_account_id' => ['nullable', 'exists:accounts,id'],
            'from_account_id' => ['nullable', 'exists:accounts,id'],
            'to_account_id' => ['nullable', 'exists:accounts,id'],
            'amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            // The voucher's branch (plan 12, BR12); every line follows it.
            // Optional: default the actor's posting branch, as before.
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'lines' => ['nullable', 'array'],
            'lines.*.account_id' => ['required_with:lines', 'exists:accounts,id', 'distinct'],
            'lines.*.amount' => ['required_with:lines', 'numeric', 'decimal:0,2', 'min:0.01'],
            'lines.*.narration' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $accountIds = [
                $data['bank_account_id'] ?? null,
                $data['from_account_id'] ?? null,
                $data['to_account_id'] ?? null,
                ...array_column($data['lines'] ?? [], 'account_id'),
            ];

            ManufacturingAccounts::assertNotControlAccounts($accountIds);
            HrAccounts::assertNotControlAccounts($accountIds);
            BranchAccounts::assertNotControlAccounts($accountIds);

            // Resolved and checked here, like the manual journal's header, so
            // a refused branch lands on the branch picker as a field error
            // and the voucher carries an explicit branch (no trait stamp).
            $data['branch_id'] = $this->usableBranchId($request->user(), $data['branch_id'] ?? null, 'branch_id');

            $voucher = JournalVoucher::postCashBank($data, $request->user());
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.journal-vouchers.index')
            ->with('status', 'Voucher posted.')
            ->with('created', $this->createdPayload($voucher));
    }

    /**
     * Names exactly the voucher just posted (CONTRACTS C11), so the form's
     * "Save & Print" opens that voucher's print view instead of guessing the
     * newest row out of the list it was redirected to.
     *
     * @return array{type: string, id: int, print_url: string}
     */
    private function createdPayload(JournalVoucher $voucher): array
    {
        return [
            'type' => 'journal_voucher',
            'id' => $voucher->id,
            'print_url' => route('tenant.journal-vouchers.print', $voucher),
        ];
    }

    public function cancel(Request $request, JournalVoucher $journalVoucher): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $journalVoucher->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.journal-vouchers.index')->with('status', 'Journal voucher cancelled.');
    }

    /**
     * PDF print of one journal voucher (T14, CONTRACTS C9): every print
     * goes through PrintLog::record() so a reprint is stamped "Copy of
     * Original". No amount-in-words - that's C9's rule for invoices and
     * notes only, and a journal voucher is neither.
     */
    public function print(Request $request, JournalVoucher $journalVoucher): HttpResponse
    {
        $journalVoucher->load(['fiscalYear', 'lines.account:id,code,name', 'creator:id,name']);

        $copyNumber = PrintLog::record($journalVoucher, $request->user());

        $pdf = Pdf::loadView('pdf.journal-voucher', [
            'voucher' => $journalVoucher,
            'company' => CompanySetting::current(),
            'documentNumber' => $journalVoucher->printedNumber($journalVoucher->voucher_type->value),
            'documentDate' => $journalVoucher->date->toDateString(),
            'dateAd' => $journalVoucher->date->toDateString(),
            'dateBs' => NepaliCalendar::formatBs($journalVoucher->date),
            'fiscalYearName' => $journalVoucher->fiscalYear?->name,
            'copyNumber' => $copyNumber,
        ]);

        return $pdf->stream("journal-voucher-{$journalVoucher->id}.pdf");
    }
}
