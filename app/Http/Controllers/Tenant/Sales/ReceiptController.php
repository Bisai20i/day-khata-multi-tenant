<?php

namespace App\Http\Controllers\Tenant\Sales;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\PrintLog;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Support\AmountInWords;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchContext;
use App\Support\Branches\BranchFormOptions;
use App\Support\Branches\BranchListFilter;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class ReceiptController extends Controller
{
    /**
     * The allocation checklist lists every sale of the selected customer
     * that still has something outstanding, per Sale::outstandingAmount()
     * (the single source of truth the model's own guard uses). The list
     * filters on "outstanding is positive", exactly - the old `> 0.01`
     * filter hid invoices that a one-paisa rounding error had left behind
     * and made them unsettleable (audit P0-3/P0-4).
     */
    public function index(Request $request): Response
    {
        // Branches (plan 12, BR12): the list follows the top-bar switcher,
        // names each row's branch only when several branches can appear,
        // and tags each open invoice with its branch so the form can show
        // where an allocation lands. A tenant that is not multi-branch gets
        // the same query and payload as before.
        $isMultiBranch = ActiveBranchSelection::isMultiBranch($request);
        $receipts = BranchListFilter::apply(Receipt::query(), $request)
            ->with(['customer:id,name', 'allocations.sale:id,total,invoice_number'])
            ->when(BranchListFilter::columnVisible($request), fn ($query) => $query->with('branch:id,name,code'))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        if (BranchContext::allowedBranchIds() !== null) {
            $receipts->through(fn (Receipt $receipt): Receipt => $this->hideOtherBranchInvoices($receipt));
        }

        return Inertia::render('Tenant/Sales/Receipts/Index', [
            'receipts' => $receipts,
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            // Never the inter-branch control accounts, which only the system
            // posts (BranchAccounts).
            'accounts' => BranchAccounts::exclude(Account::query())->orderBy('name')->get(['id', 'code', 'name']),
            'outstandingSales' => Sale::query()
                ->where('status', 'posted')
                ->with('customer:id,name')
                ->orderBy('date')
                ->get()
                ->map(fn (Sale $sale) => [
                    'id' => $sale->id,
                    'customer_id' => $sale->customer_id,
                    'date' => $sale->date->format('Y-m-d'),
                    'invoice_number' => $sale->invoice_number,
                    'total' => (string) $sale->total,
                    'outstanding' => Money::of($sale->outstandingAmount()),
                    ...($isMultiBranch ? ['branch_id' => (int) $sale->branch_id] : []),
                ])
                ->filter(fn (array $sale) => $sale['outstanding']->isPositive())
                ->map(fn (array $sale) => [...$sale, 'outstanding' => $sale['outstanding']->toString()])
                ->values(),
            ...BranchFormOptions::props($request),
        ]);
    }

    /**
     * For a branch-restricted viewer (plan 12, I6): an allocation whose
     * invoice sits in a branch they cannot see comes back with no sale
     * (the Sale access scope hides it). Strip the invoice id as well and
     * flag the row, so the page can say "Invoice in another branch" with
     * only the applied amount, and learns nothing else about that invoice.
     */
    private function hideOtherBranchInvoices(Receipt $receipt): Receipt
    {
        foreach ($receipt->allocations as $allocation) {
            if ($allocation->sale === null) {
                $allocation->setAttribute('sale_id', null);
                $allocation->setAttribute('in_other_branch', true);
            }
        }

        return $receipt;
    }

    /**
     * `distinct` on `allocations.*.sale_id` rejects a payload that names the
     * same invoice twice (audit P0-14); Receipt::post() additionally sums per
     * invoice before checking its outstanding balance, so neither layer
     * depends on the other. The `decimal` rules keep an amount from carrying
     * more decimals than the column stores (P0-5).
     *
     * AuthorizationException is caught alongside InvalidArgumentException
     * because the fiscal-year guard raises it when a non-admin dates a
     * receipt into a year that was reopened for correction: that belongs on
     * the form as a message, not as a 403 page that loses the entry.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'payment_mode' => ['required', 'in:cash,bank'],
            'bank_account_id' => ['nullable', 'integer', Rule::in(SalesReturn::refundAccountQuery()->pluck('id')->all())],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'narration' => ['nullable', 'string', 'max:255'],
            // Where the money came in (plan 12, BR07); Receipt::post()
            // checks it is active and accessible, default the posting branch.
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.sale_id' => ['required_with:allocations', 'integer', 'distinct', 'exists:sales,id'],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'decimal:0,2', 'min:0.01'],
        ]);

        $this->assertAllocationsNotBefore($data);

        try {
            Receipt::post($data, $request->user());
        } catch (InvalidArgumentException|AuthorizationException $e) {
            // A refused branch belongs on the branch picker; allocation,
            // amount and clearing-account problems stay under `amount`.
            return back()->withErrors([BranchFormOptions::errorField($e, 'amount') => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.receipts.index')->with('status', 'Receipt recorded.');
    }

    /**
     * Printable money receipt (flags G-07): number, customer, how the money
     * came in, the invoices it settles and the amount in words. Every print is
     * logged, so a reprint says "Copy of Original" (CONTRACTS C9). The
     * receipt's number is its voucher number, the same one the customer's
     * ledger shows against it.
     */
    public function print(Request $request, Receipt $receipt): HttpResponse
    {
        $receipt->load([
            'customer', 'bankAccount', 'journalVoucher.fiscalYear', 'canceller:id,name',
            'allocations.sale:id,invoice_number,date,total',
        ]);

        $documentDate = $receipt->date->toDateString();
        $amount = Money::of($receipt->amount);
        $voucherNumber = $receipt->journalVoucher?->seriesNumber();

        return Pdf::loadView('pdf.receipt', [
            'receipt' => $receipt,
            'company' => CompanySetting::current(),
            'documentNumber' => $voucherNumber !== null ? "Receipt #{$voucherNumber}" : "#{$receipt->id}",
            'documentDate' => $documentDate,
            'amount' => $amount,
            'allocated' => Money::sum($receipt->allocations->map(fn ($allocation) => Money::of($allocation->amount))),
            'copyNumber' => PrintLog::record($receipt, $request->user()),
            'dateAd' => $documentDate,
            'dateBs' => NepaliCalendar::formatBs($documentDate),
            'fiscalYearName' => $receipt->journalVoucher?->fiscalYear?->name,
            'amountInWords' => AmountInWords::rupees($amount),
        ])->stream("receipt-{$receipt->id}.pdf");
    }

    public function cancel(Request $request, Receipt $receipt): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $receipt->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.receipts.index')->with('status', 'Receipt cancelled.');
    }

    /**
     * Puts "dated after this receipt" on the allocation row itself, so the
     * user sees which invoice is wrong (flags G-14). The model re-checks the
     * same rule under its lock.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertAllocationsNotBefore(array $data): void
    {
        $allocations = $data['allocations'] ?? [];

        if ($allocations === []) {
            return;
        }

        $date = Carbon::parse($data['date'])->toDateString();
        $documentDates = Sale::query()
            ->whereIn('id', array_column($allocations, 'sale_id'))
            ->get(['id', 'date'])
            ->mapWithKeys(fn (Sale $document) => [$document->id => $document->date->toDateString()]);

        $errors = [];

        foreach ($allocations as $index => $allocation) {
            $documentDate = $documentDates->get((int) $allocation['sale_id']);

            if ($documentDate !== null && $documentDate > $date) {
                $errors["allocations.{$index}.sale_id"] = "This invoice is dated {$documentDate}, after this receipt ({$date}).";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
