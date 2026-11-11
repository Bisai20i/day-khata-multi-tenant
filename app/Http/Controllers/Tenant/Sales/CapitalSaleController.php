<?php

namespace App\Http\Controllers\Tenant\Sales;

use App\Http\Controllers\Concerns\OffersDocumentBranch;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CapitalSale;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\PrintLog;
use App\Support\AmountInWords;
use App\Support\Billing\BillingException;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchFormOptions;
use App\Support\Branches\BranchListFilter;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class CapitalSaleController extends Controller
{
    use OffersDocumentBranch;

    /**
     * Branches (plan 12, BR12): the list follows the top-bar switcher and
     * names each row's branch only when several branches can appear; the
     * create form gets a branch picker and stores that know their branch.
     * A company that is not multi-branch gets the same query and payload.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Tenant/Sales/CapitalSales/Index', [
            'capitalSales' => BranchListFilter::apply(CapitalSale::query(), $request)
                ->with(['customer:id,name', 'lines.account:id,code,name', 'journalVoucher:id,voucher_number'])
                ->when(BranchListFilter::columnVisible($request), fn ($query) => $query->with('branch:id,name,code'))
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            // The inter-branch control accounts are posted only by the system
            // (BranchAccounts), so the pickers never offer them.
            'accounts' => BranchAccounts::exclude(Account::query())->orderBy('name')->get(['id', 'code', 'name']),
            'stores' => $this->branchStoreOptions($request),
            'defaultVatRate' => (string) (CompanySetting::current()->default_vat_rate ?? '13.00'),
            ...BranchFormOptions::props($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'date' => ['required', 'date'],
            'narration' => ['nullable', 'string', 'max:255'],
            'payment_mode' => ['required', 'in:cash,bank,partial,credit'],
            'bank_account_id' => ['nullable', 'exists:accounts,id', BranchAccounts::notControlAccountRule()],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            // Sent by the branch picker of a multi-branch company (BR12),
            // absent otherwise: CapitalSale::post() falls back to the store's
            // branch, then the user's posting branch, and refuses a store of
            // another branch or a branch the user cannot use.
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'cash_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'bank_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'expected_total' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'exists:accounts,id', BranchAccounts::notControlAccountRule()],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'lines.*.vatable' => ['nullable', 'boolean'],
            'lines.*.narration' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $capitalSale = CapitalSale::post($data, $data['lines'], $request->user());
        } catch (BillingException $e) {
            // C8: the browser previewed a total with money.js and sent it back.
            // A difference means the bill on screen was not the bill being
            // saved, so the save is refused rather than booking another amount.
            throw ValidationException::withMessages(
                $e->reason === BillingException::REASON_TOTAL_MISMATCH
                    ? ['expected_total' => 'The bill total changed. Please review it before saving.']
                    : ['lines' => $e->getMessage()]
            );
        } catch (InvalidArgumentException|AuthorizationException $e) {
            // A refused branch goes on the branch picker and a store of
            // another branch on the store field; the rest stays on `lines`.
            return back()->withErrors([BranchFormOptions::errorField($e, 'lines', 'branch_id', 'store_id') => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.capital-sales.index')
            ->with('status', 'Capital sale posted.')
            ->with('created', [
                'type' => 'capital-sale',
                'id' => $capitalSale->id,
                'print_url' => route('tenant.capital-sales.print', $capitalSale),
            ]);
    }

    public function cancel(Request $request, CapitalSale $capitalSale): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $capitalSale->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.capital-sales.index')->with('status', 'Capital sale cancelled.');
    }

    /**
     * Streams the capital sale tax invoice inline, the same way the sale and
     * purchase PDFs do.
     *
     * A capital sale credits Output VAT and is a tax invoice in its own right,
     * but it had no printable document at all - the customer got a ledger
     * entry and nothing to keep. Every figure printed is the one stored on the
     * row, never a recomputation (C8), and the invoice number is the stored
     * one, never re-derived from the current settings (C7).
     */
    public function print(Request $request, CapitalSale $capitalSale): HttpResponse
    {
        $capitalSale->load(['customer', 'bankAccount', 'lines.account', 'journalVoucher', 'fiscalYear']);

        $documentDate = $capitalSale->date->format('Y-m-d');
        $total = Money::of($capitalSale->total);

        return Pdf::loadView('pdf.capital-sale', [
            'capitalSale' => $capitalSale,
            'company' => CompanySetting::current(),
            'documentNumber' => $capitalSale->documentNumber(),
            'documentDate' => $documentDate,
            'taxable' => Money::of($capitalSale->taxable_amount),
            'nontaxable' => Money::of($capitalSale->nontaxable_amount),
            'vat' => Money::of($capitalSale->vat_amount),
            'total' => $total,
            'copyNumber' => PrintLog::record($capitalSale, $request->user()),
            'dateAd' => $documentDate,
            'dateBs' => NepaliCalendar::formatBs($documentDate),
            'fiscalYearName' => $capitalSale->fiscalYear?->name,
            'amountInWords' => AmountInWords::rupees($total),
            // Multi-branch only; null keeps a single-branch invoice unchanged.
            ...$this->printBranch($request, $capitalSale),
        ])->stream("capital-sale-{$capitalSale->id}.pdf");
    }
}
