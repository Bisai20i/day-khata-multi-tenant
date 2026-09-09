<?php

namespace App\Http\Controllers\Tenant\Sales;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Store;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
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
     * already-effective return documents, the historical list this page has
     * always shown) and `pendingRequests` (status pending/rejected - the
     * request/approval queue from SalesReturn::request()/approve()/
     * reject()). Mirrors legacy day_khata's own separation between
     * `listreturnoutstock` (real returns) and `listreturnoutstockrequest`
     * (the request queue, which itself lists both still-pending and
     * already-declined requests together - see that view's "Return Status"
     * column). `pendingRequests` isn't paginated - it's a small, actionable
     * queue, not a growing historical log.
     */
    public function index(Request $request): Response
    {
        $from = $request->filled('from') ? $request->string('from')->toString() : null;
        $to = $request->filled('to') ? $request->string('to')->toString() : null;
        $customerId = $request->filled('customer_id') ? (int) $request->input('customer_id') : null;

        $returns = SalesReturn::query()
            ->whereIn('status', ['posted', 'cancelled'])
            ->with(['sale.customer:id,name', 'lines.saleLine.item:id,name,unit'])
            ->when($from, fn ($query, string $from) => $query->whereDate('date', '>=', $from))
            ->when($to, fn ($query, string $to) => $query->whereDate('date', '<=', $to))
            ->when($customerId, fn ($query, int $customerId) => $query->whereHas(
                'sale', fn ($query) => $query->where('customer_id', $customerId)
            ))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $pendingRequests = SalesReturn::query()
            ->whereIn('status', ['pending', 'rejected'])
            ->with(['sale.customer:id,name', 'lines.saleLine.item:id,name,unit', 'creator:id,name'])
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
            'sales' => Sale::query()
                ->where('status', 'posted')
                ->with(['customer:id,name', 'lines.item:id,name,unit'])
                ->orderByDesc('date')
                ->get(),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'accounts' => Account::query()->orderBy('name')->get(['id', 'code', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedReturn($request);

        try {
            SalesReturn::post(
                [
                    'sale_id' => $data['sale_id'],
                    'date' => $data['date'],
                    'reason' => $data['reason'] ?? null,
                    'refund_account_id' => $data['refund_account_id'] ?? null,
                    'store_id' => $data['store_id'] ?? null,
                ],
                $data['lines'],
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
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
     * separate RBAC decision this task doesn't attempt.
     */
    public function requestReturn(Request $request): RedirectResponse
    {
        $data = $this->validatedReturn($request);

        try {
            SalesReturn::request(
                [
                    'sale_id' => $data['sale_id'],
                    'date' => $data['date'],
                    'reason' => $data['reason'] ?? null,
                    'refund_account_id' => $data['refund_account_id'] ?? null,
                    'store_id' => $data['store_id'] ?? null,
                ],
                $data['lines'],
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.sales-returns.index')->with('status', 'Return request submitted for approval.');
    }

    public function approve(Request $request, SalesReturn $salesReturn): RedirectResponse
    {
        try {
            $salesReturn->approve($request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['salesReturn' => $e->getMessage()]);
        }

        return redirect()->route('tenant.sales-returns.index')->with('status', 'Return request approved and posted.');
    }

    public function reject(Request $request, SalesReturn $salesReturn): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
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
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $salesReturn->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.sales-returns.index')->with('status', 'Sales return cancelled.');
    }

    /**
     * Shared validation for both a direct post() (store()) and a deferred
     * request() (requestReturn()) - same shape either way, only what
     * happens with the validated data afterward differs.
     *
     * @return array{sale_id: int, date: string, reason: ?string, refund_account_id: ?int, store_id: ?int, lines: array<int, array{sale_line_id: int, quantity: float}>}
     */
    private function validatedReturn(Request $request): array
    {
        return $request->validate([
            'sale_id' => ['required', 'exists:sales,id'],
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'refund_account_id' => ['nullable', 'exists:accounts,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_line_id' => ['required', 'exists:sale_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        ]);
    }

    /**
     * Streams a printable PDF credit note inline (not a forced download), so
     * it opens in a new browser tab from a plain anchor link on the Index
     * page - same pattern as SaleController::print().
     */
    public function print(SalesReturn $salesReturn): HttpResponse
    {
        $salesReturn->load(['sale.customer', 'lines.saleLine.item', 'journalVoucher', 'refundAccount']);

        $documentNumber = $salesReturn->journalVoucher
            ? "SR-{$salesReturn->journalVoucher->voucher_number}"
            : "SR-{$salesReturn->id}";

        return Pdf::loadView('pdf.sales-return', [
            'salesReturn' => $salesReturn,
            'company' => CompanySetting::current(),
            'documentNumber' => $documentNumber,
            'documentDate' => $salesReturn->date->format('Y-m-d'),
        ])->stream("sales-return-{$salesReturn->id}.pdf");
    }
}
