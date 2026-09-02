<?php

namespace App\Http\Controllers\Tenant\Sales;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Agent;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Sale;
use App\Models\Store;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SaleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Sales/Index', [
            'sales' => Sale::query()
                ->with(['customer:id,name', 'agent:id,name', 'lines.item:id,name,unit', 'journalVoucher:id,voucher_type,voucher_number'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'items' => Item::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'unit', 'is_vatable', 'is_stockable']),
            'accounts' => Account::query()->orderBy('name')->get(['id', 'code', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'agents' => Agent::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'commission_rate']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'invoice_type' => ['required', 'in:abbreviated,full'],
            'date' => ['required', 'date'],
            'payment_mode' => ['required', 'in:cash,bank,partial,credit'],
            'bank_account_id' => ['nullable', 'exists:accounts,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'vat_rate' => ['nullable', 'numeric', 'min:0'],
            'cash_amount' => ['nullable', 'numeric', 'min:0'],
            'bank_amount' => ['nullable', 'numeric', 'min:0'],
            'tds_account_id' => ['nullable', 'exists:accounts,id'],
            'tds_amount' => ['nullable', 'numeric', 'min:0'],
            'agent_id' => ['nullable', 'exists:agents,id'],
            'commission_amount' => ['nullable', 'numeric', 'min:0'],
            'narration' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            Sale::post(
                Arr::except($data, ['lines']),
                $data['lines'],
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.sales.index')->with('status', 'Sale posted.');
    }

    public function cancel(Request $request, Sale $sale): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $sale->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.sales.index')->with('status', 'Sale cancelled.');
    }

    /**
     * Streams a printable PDF invoice inline (not a forced download), so it
     * opens in a new browser tab from a plain anchor link on the Index page.
     */
    public function print(Sale $sale): HttpResponse
    {
        $sale->load(['customer', 'agent', 'bankAccount', 'lines.item', 'journalVoucher']);

        $prefix = $sale->invoice_type === 'abbreviated' ? 'SLA' : 'SL';
        $documentNumber = $sale->journalVoucher
            ? "{$prefix}-{$sale->journalVoucher->voucher_number}"
            : "SL-{$sale->id}";

        return Pdf::loadView('pdf.sale', [
            'sale' => $sale,
            'company' => CompanySetting::current(),
            'documentNumber' => $documentNumber,
            'documentDate' => $sale->date->format('Y-m-d'),
        ])->stream("sale-{$sale->id}.pdf");
    }
}
