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
     */
    public function index(Request $request): Response
    {
        $from = $request->filled('from') ? $request->string('from')->toString() : null;
        $to = $request->filled('to') ? $request->string('to')->toString() : null;
        $customerId = $request->filled('customer_id') ? (int) $request->input('customer_id') : null;

        $returns = SalesReturn::query()
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

        return Inertia::render('Tenant/Sales/Returns/Index', [
            'returns' => $returns,
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
        $data = $request->validate([
            'sale_id' => ['required', 'exists:sales,id'],
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'refund_account_id' => ['nullable', 'exists:accounts,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_line_id' => ['required', 'exists:sale_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        ]);

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
