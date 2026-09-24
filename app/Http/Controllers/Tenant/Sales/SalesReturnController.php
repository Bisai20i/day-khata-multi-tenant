<?php

namespace App\Http\Controllers\Tenant\Sales;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Item;
use App\Models\PrintLog;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Store;
use App\Support\AmountInWords;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SalesReturnController extends Controller
{
    /**
     * Listing is server-side filtered (date range + customer, the latter
     * via the parent sale since a return has no customer_id of its own) and
     * paginated - same `when()`/`paginate()->withQueryString()` shape
     * Central\Tenants\TenantController::index() established.
     *
     * Split into two props: `returns` (status posted/cancelled - real,
     * already-effective credit notes, the historical list this page has
     * always shown) and `pendingRequests` (status pending/rejected - the
     * request/approval queue from SalesReturn::request()/approve()/
     * reject()). Mirrors legacy day_khata's own separation between
     * `listreturnoutstock` (real returns) and `listreturnoutstockrequest`
     * (the request queue, which itself lists both still-pending and
     * already-declined requests together - see that view's "Return Status"
     * column). `pendingRequests` isn't paginated - it's a small, actionable
     * queue, not a growing historical log.
     *
     * The original-sale picker is server-side searched and paginated
     * (`sale_search`, `sale_page`) instead of shipping every posted sale
     * with its lines to the browser, and the selected sale's returnable
     * detail is an optional prop the form pulls on demand.
     */
    public function index(Request $request): Response
    {
        $from = $request->filled('from') ? $request->string('from')->toString() : null;
        $to = $request->filled('to') ? $request->string('to')->toString() : null;
        $customerId = $request->filled('customer_id') ? (int) $request->input('customer_id') : null;

        $returns = SalesReturn::query()
            ->whereIn('status', ['posted', 'cancelled'])
            ->with([
                'sale:id,customer_id,invoice_number', 'sale.customer:id,name',
                // An unlinked return has no parent sale to read a customer or
                // an item off (C7 "returns without a bill"), so its own
                // `customer_id` and per-line `item_id` are loaded alongside.
                'customer:id,name', 'lines.saleLine.item:id,name,unit', 'lines.item:id,name,unit',
            ])
            ->when($from, fn ($query, string $from) => $query->whereDate('date', '>=', $from))
            ->when($to, fn ($query, string $to) => $query->whereDate('date', '<=', $to))
            ->when($customerId, fn ($query, int $customerId) => $query->where(
                fn ($query) => $query->whereHas('sale', fn ($sale) => $sale->where('customer_id', $customerId))
                    ->orWhere('customer_id', $customerId)
            ))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $pendingRequests = SalesReturn::query()
            ->whereIn('status', ['pending', 'rejected'])
            ->with(['sale:id,customer_id,invoice_number', 'sale.customer:id,name', 'lines.saleLine.item:id,name,unit', 'creator:id,name'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Tenant/Sales/Returns/Index', [
            'returns' => $returns,
            'pendingRequests' => $pendingRequests,
            'filters' => [
                'from' => $from,
                'to' => $to,
                'customer_id' => $customerId,
            ],
            'saleSearch' => $request->filled('sale_search') ? trim($request->string('sale_search')->toString()) : '',
            'sales' => $this->salePicker($request),
            'selectedSale' => Inertia::optional(fn () => $this->selectedSale($request)),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            // Unlinked returns (C7 "returns without a bill") price a line
            // the same way a fresh sale would, so the form needs the same
            // item picker Sales/Create.vue gets.
            'items' => Item::query()->where('is_active', true)->orderBy('name')
                ->with(['units' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
                ->get(['id', 'name', 'unit', 'is_vatable', 'is_stockable', 'sale_rate']),
            'walkInCustomerId' => Customer::walkIn()?->id,
            'refundAccounts' => SalesReturn::refundAccountQuery()->orderBy('name')->get(['id', 'code', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(),
            'invoiceSettings' => [
                'default_vat_rate' => CompanySetting::current()->default_vat_rate,
            ],
        ]);
    }

    /**
     * One page of the "which sale is this against?" picker, searched by
     * stored invoice number, sale id or customer name.
     */
    private function salePicker(Request $request): LengthAwarePaginator
    {
        $search = $request->filled('sale_search') ? trim($request->string('sale_search')->toString()) : null;

        return Sale::query()
            ->where('status', 'posted')
            ->with('customer:id,name')
            ->when($search, fn ($query, string $search) => $query->where(function ($query) use ($search) {
                $query->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('id', $search)
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%"));
            }))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'sale_page')
            ->withQueryString()
            ->through(fn (Sale $sale) => [
                'id' => $sale->id,
                'date' => $sale->date->format('Y-m-d'),
                'invoice_number' => $sale->invoice_number,
                'customer' => $sale->customer?->name,
                'total' => (string) $sale->total,
                'store_id' => $sale->store_id,
            ]);
    }

    /**
     * The picked sale with everything the form needs to price a return:
     * each line's item, unit, sold quantity, what is still returnable, and
     * the C6 components the preview multiplies (see
     * SalesReturn::returnableLines()).
     *
     * @return array<string, mixed>|null
     */
    private function selectedSale(Request $request): ?array
    {
        if (! $request->filled('sale_id')) {
            return null;
        }

        $sale = Sale::query()
            ->where('status', 'posted')
            ->with('customer:id,name')
            ->find((int) $request->input('sale_id'));

        if (! $sale) {
            return null;
        }

        return [
            'id' => $sale->id,
            'date' => $sale->date->format('Y-m-d'),
            'invoice_number' => $sale->invoice_number,
            'customer' => $sale->customer?->name,
            'store_id' => $sale->store_id,
            'total' => (string) $sale->total,
            'lines' => SalesReturn::returnableLines($sale),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedReturn($request);

        try {
            SalesReturn::post($this->header($data), $data['lines'], $request->user());
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return $this->failed($e);
        }

        return redirect()->route('tenant.sales-returns.index')->with('status', 'Sales return posted.');
    }

    /**
     * A return with no bill this system ever issued to point at (audit
     * section 3 "Sales", "returns without a bill") - see SalesReturn::
     * postUnlinked()'s docblock. Always posts directly: there is no original
     * invoice for a later approval step to re-check anything against.
     */
    public function storeUnlinked(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'refund_account_id' => ['nullable', 'integer', Rule::in(SalesReturn::refundAccountQuery()->pluck('id')->all())],
            'refund_cash_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'refund_bank_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'expected_total' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.item_unit_id' => ['nullable', 'integer', 'exists:item_units,id'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.rate' => ['required', 'decimal:0,4', 'min:0'],
            'lines.*.bonus_quantity' => ['nullable', 'decimal:0,4', 'min:0'],
        ]);

        try {
            SalesReturn::postUnlinked(
                Arr::except($data, ['lines']),
                $data['lines'],
                $request->user(),
            );
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return $this->failed($e);
        }

        return redirect()->route('tenant.sales-returns.index')->with('status', 'Sales return posted.');
    }

    /**
     * Requests a return WITHOUT posting anything yet - see
     * SalesReturn::request()'s own docblock. Anyone who can reach this
     * screen can request one (no separate role for "requester" exists in
     * this app, matching legacy's own open-to-any-logged-in-user model);
     * approve()/reject() are likewise open to any authenticated tenant user
     * for the same reason this app doesn't otherwise gate day-to-day
     * transaction entry - a real maker/checker role split is a bigger,
     * separate RBAC decision this task doesn't attempt. Cancelling a posted
     * credit note is admin-only (C5), since that reverses real money.
     */
    public function requestReturn(Request $request): RedirectResponse
    {
        $data = $this->validatedReturn($request);

        try {
            SalesReturn::request($this->header($data), $data['lines'], $request->user());
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return $this->failed($e);
        }

        return redirect()->route('tenant.sales-returns.index')->with('status', 'Return request submitted for approval.');
    }

    public function approve(Request $request, SalesReturn $salesReturn): RedirectResponse
    {
        // Maker/checker (SAL-04): the person who raised the request cannot approve it.
        if ($salesReturn->created_by !== null && (int) $salesReturn->created_by === (int) $request->user()->id) {
            return back()->withErrors(['salesReturn' => 'You cannot approve a return request you created yourself.']);
        }

        try {
            $salesReturn->approve($request->user());
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['salesReturn' => $e->getMessage()]);
        }

        return redirect()->route('tenant.sales-returns.index')->with('status', 'Return request approved and posted.');
    }

    public function reject(Request $request, SalesReturn $salesReturn): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $salesReturn->reject($data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.sales-returns.index')->with('status', 'Return request rejected.');
    }

    public function cancel(Request $request, SalesReturn $salesReturn): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $salesReturn->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.sales-returns.index')->with('status', 'Sales return cancelled.');
    }

    /**
     * Shared validation for both a direct post() (store()) and a deferred
     * request() (requestReturn()) - same shape either way, only what
     * happens with the validated data afterward differs.
     *
     * `distinct` on `lines.*.sale_line_id` rejects a payload that names the
     * same original line twice (audit P0-14); the model additionally sums
     * per line before checking the cap, so neither layer relies on the
     * other. The `decimal` rules keep an input from carrying more decimals
     * than its column can hold (P0-5).
     *
     * `refund_cash_amount`/`refund_bank_amount` are the optional split
     * refund (audit section 4 polish): both must be present-or-absent
     * together as far as this layer cares, with the exact "they add up to
     * the customer credit" rule enforced where the credit is actually known
     * - SalesReturn::postRefund() via DocumentCalculator::assertExactSplit()
     * (C3). Leaving both blank keeps the original single-account behaviour.
     *
     * @return array{sale_id: int, date: string, reason: ?string, refund_account_id: ?int, refund_cash_amount: ?string, refund_bank_amount: ?string, store_id: ?int, expected_total: ?string, lines: array<int, array{sale_line_id: int, quantity: string, bonus_quantity?: string}>}
     */
    private function validatedReturn(Request $request): array
    {
        return $request->validate([
            'sale_id' => ['required', 'integer', 'exists:sales,id'],
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'refund_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'refund_cash_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'refund_bank_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'expected_total' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_line_id' => ['required', 'integer', 'distinct', 'exists:sale_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'min:0.0001'],
            // Bonus/free units returned alongside the paid quantity (audit
            // section 3 "Sales"): restocks, credits nothing.
            'lines.*.bonus_quantity' => ['nullable', 'decimal:0,4', 'min:0'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{sale_id: int, date: string, reason: ?string, refund_account_id: ?int, refund_cash_amount: ?string, refund_bank_amount: ?string, store_id: ?int, expected_total: ?string}
     */
    private function header(array $data): array
    {
        return [
            'sale_id' => (int) $data['sale_id'],
            'date' => $data['date'],
            'reason' => $data['reason'] ?? null,
            'refund_account_id' => $data['refund_account_id'] ?? null,
            'refund_cash_amount' => $data['refund_cash_amount'] ?? null,
            'refund_bank_amount' => $data['refund_bank_amount'] ?? null,
            'store_id' => $data['store_id'] ?? null,
            'expected_total' => $data['expected_total'] ?? null,
        ];
    }

    /**
     * A total that no longer matches what the browser previewed is reported
     * on `expected_total` with the wording C8 fixes, so the form can point
     * at the totals block instead of a line.
     *
     * AuthorizationException is caught alongside InvalidArgumentException
     * because the fiscal-year guard raises it for a non-admin posting into a
     * reopened year: that belongs on the form as a message, not as a 403
     * page that loses everything typed.
     */
    private function failed(InvalidArgumentException|AuthorizationException $exception): RedirectResponse
    {
        $key = str_contains($exception->getMessage(), 'total changed') ? 'expected_total' : 'lines';

        return back()->withErrors([$key => $exception->getMessage()])->withInput();
    }

    /**
     * Streams a printable credit note inline (not a forced download), so it
     * opens in a new browser tab from a plain anchor link on the Index page
     * - same pattern as SaleController::print().
     *
     * Every print is logged and numbered (C9): the first is the original,
     * every later one prints as a copy. A pending or rejected request is
     * printed as a request, never as a numbered credit note (C7).
     */
    public function print(Request $request, SalesReturn $salesReturn): HttpResponse
    {
        $salesReturn->load([
            'sale.customer', 'lines.saleLine.item', 'lines.saleLine.itemUnit',
            // An unlinked note names its own customer, items and units
            // (C7 "returns without a bill"): there is no sale to inherit
            // any of them from, so the view falls back to these.
            'customer', 'lines.item', 'lines.itemUnit',
            'journalVoucher', 'refundAccount', 'fiscalYear',
        ]);

        $isCreditNote = in_array($salesReturn->status, ['posted', 'cancelled'], true);

        return Pdf::loadView('pdf.sales-return', [
            'salesReturn' => $salesReturn,
            'company' => CompanySetting::current(),
            'isCreditNote' => $isCreditNote,
            'documentNumber' => $salesReturn->documentNumber(),
            'documentDate' => $salesReturn->date->format('Y-m-d'),
            'dateAd' => $salesReturn->date->format('Y-m-d'),
            'dateBs' => NepaliCalendar::formatBs($salesReturn->date),
            'fiscalYearName' => $salesReturn->fiscalYear?->name ?? $salesReturn->journalVoucher?->fiscalYear?->name,
            'copyNumber' => PrintLog::record($salesReturn, $request->user()),
            'amountInWords' => AmountInWords::rupees(Money::of($salesReturn->total)),
        ])->stream("sales-return-{$salesReturn->id}.pdf");
    }
}
