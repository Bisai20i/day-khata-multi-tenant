<?php

namespace App\Http\Controllers\Tenant\Sales;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Receipt;
use App\Models\Sale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class ReceiptController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Sales/Receipts/Index', [
            'receipts' => Receipt::query()
                ->with(['customer:id,name', 'allocations.sale:id,total'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'accounts' => Account::query()->orderBy('name')->get(['id', 'code', 'name']),
            'outstandingSales' => Sale::query()
                ->where('status', 'posted')
                ->where('payment_mode', 'credit')
                ->orderBy('date')
                ->get()
                ->map(fn (Sale $sale) => [
                    'id' => $sale->id,
                    'customer_id' => $sale->customer_id,
                    'date' => $sale->date,
                    'total' => (float) $sale->total,
                    'outstanding' => $sale->outstandingAmount(),
                ])
                ->filter(fn (array $sale) => $sale['outstanding'] > 0.01)
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_mode' => ['required', 'in:cash,bank'],
            'bank_account_id' => ['nullable', 'exists:accounts,id'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'narration' => ['nullable', 'string', 'max:255'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.sale_id' => ['required_with:allocations', 'exists:sales,id'],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'min:0.01'],
        ]);

        try {
            Receipt::post($data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.receipts.index')->with('status', 'Receipt recorded.');
    }

    public function cancel(Request $request, Receipt $receipt): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $receipt->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.receipts.index')->with('status', 'Receipt cancelled.');
    }
}
