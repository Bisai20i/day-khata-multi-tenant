<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Enums\VoucherType;
use App\Exports\JournalVoucherListExport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\PrintLog;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
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
            'journalVouchers' => $this->filteredVouchersQuery($filters)
                ->with(['fiscalYear:id,name', 'creator:id,name', 'lines.account:id,code,name'])
                ->paginate(25)
                ->withQueryString(),
            'filters' => $filters,
            'accounts' => Account::query()->orderBy('name')->get(['id', 'code', 'name']),
            // The one closed year currently reopened for correction, if
            // any - lets Create.vue offer it as the only non-current
            // fiscal-year option, per the locked design decision (see
            // ClosedFiscalYearGuard's docblock). Replaces the previous
            // "every fiscal year, closed or not" picker, which allowed an
            // admin+reason override into any closed year regardless of
            // whether it had been deliberately reopened.
            'correctionFiscalYear' => FiscalYear::openForCorrection()?->only(['id', 'name', 'reopen_reason']),
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

        $rows = $this->filteredVouchersQuery($filters)
            ->with(['fiscalYear:id,name', 'creator:id,name'])
            ->withSum('lines as total_debit', 'debit')
            ->get()
            ->map(fn (JournalVoucher $voucher): array => [
                'date' => $voucher->date->format('Y-m-d'),
                'voucher_type' => $voucher->voucher_type->value,
                'voucher_number' => $voucher->voucher_number,
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
     * @param  array{from: ?string, to: ?string, voucher_type: ?string, search: ?string, sort: string, sort_dir: string}  $filters
     * @return Builder<JournalVoucher>
     */
    private function filteredVouchersQuery(array $filters): Builder
    {
        return JournalVoucher::query()
            ->when($filters['from'], fn ($query, string $from) => $query->whereDate('date', '>=', $from))
            ->when($filters['to'], fn ($query, string $to) => $query->whereDate('date', '<=', $to))
            ->when($filters['voucher_type'], fn ($query, string $type) => $query->where('voucher_type', $type))
            ->when($filters['search'], function ($query, string $search) {
                $number = preg_replace('/\D/', '', $search);

                $query->where(function ($query) use ($search, $number) {
                    $query->where('narration', 'like', "%{$search}%");

                    if ($number !== '') {
                        $query->orWhere('voucher_number', (int) $number);
                    }
                });
            })
            ->orderBy(self::SORTABLE_COLUMNS[$filters['sort']], $filters['sort_dir'])
            ->orderByDesc('id');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'fiscal_year_id' => ['nullable', 'exists:fiscal_years,id'],
            'reason' => ['nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'narration' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2'],
            // One line per account (flags G-20), like the cash/bank form below.
            'lines.*.account_id' => ['required', 'exists:accounts,id', 'distinct'],
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
            $voucher = JournalVoucher::post(
                Arr::only($data, ['fiscal_year_id', 'reason', 'date', 'narration']),
                $data['lines'],
                $request->user(),
            );
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.journal-vouchers.index')
            ->with('status', 'Journal voucher posted.')
            ->with('created', $this->createdPayload($voucher));
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
            'lines' => ['nullable', 'array'],
            'lines.*.account_id' => ['required_with:lines', 'exists:accounts,id', 'distinct'],
            'lines.*.amount' => ['required_with:lines', 'numeric', 'decimal:0,2', 'min:0.01'],
            'lines.*.narration' => ['nullable', 'string', 'max:255'],
        ]);

        try {
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
            'documentNumber' => "{$journalVoucher->voucher_type->value}-{$journalVoucher->voucher_number}",
            'documentDate' => $journalVoucher->date->toDateString(),
            'dateAd' => $journalVoucher->date->toDateString(),
            'dateBs' => NepaliCalendar::formatBs($journalVoucher->date),
            'fiscalYearName' => $journalVoucher->fiscalYear?->name,
            'copyNumber' => $copyNumber,
        ]);

        return $pdf->stream("journal-voucher-{$journalVoucher->id}.pdf");
    }
}
