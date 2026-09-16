<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Enums\VoucherType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\PrintLog;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class JournalVoucherController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Accounting/JournalVouchers/Index', [
            'journalVouchers' => JournalVoucher::query()
                ->with(['fiscalYear:id,name', 'creator:id,name', 'lines.account:id,code,name'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
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

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'fiscal_year_id' => ['nullable', 'exists:fiscal_years,id'],
            'reason' => ['nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'narration' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'exists:accounts,id'],
            // decimal:0,2 as well as numeric: an amount with a third decimal
            // used to pass this check, balance against another third-decimal
            // line, and then be rounded per line by MySQL into an unbalanced
            // voucher (audit P0-2). JournalVoucher::validateLines() refuses it
            // too; this is here so the user gets a field-level message.
            'lines.*.debit' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'lines.*.narration' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            JournalVoucher::post(
                Arr::only($data, ['fiscal_year_id', 'reason', 'date', 'narration']),
                $data['lines'],
                $request->user(),
            );
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.journal-vouchers.index')->with('status', 'Journal voucher posted.');
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
            JournalVoucher::postCashBank($data, $request->user());
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.journal-vouchers.index')->with('status', 'Voucher posted.');
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
