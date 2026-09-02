<?php

namespace App\Http\Controllers\Tenant\Purchases;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PaymentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Purchases/Payments/Index', [
            'payments' => Payment::query()
                ->with(['supplier:id,name', 'allocations.purchase:id,total'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'accounts' => Account::query()->orderBy('name')->get(['id', 'code', 'name']),
            'outstandingPurchases' => Purchase::query()
                ->where('payment_mode', 'credit')
                ->where('status', 'posted')
                ->orderBy('date')
                ->get(['id', 'supplier_id', 'date', 'total'])
                ->map(fn (Purchase $purchase) => [
                    'id' => $purchase->id,
                    'supplier_id' => $purchase->supplier_id,
                    'date' => $purchase->date->toDateString(),
                    'total' => (float) $purchase->total,
                    'outstanding' => $purchase->outstandingAmount(),
                ])
                ->filter(fn (array $row) => $row['outstanding'] > 0.01)
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_mode' => ['required', 'in:cash,bank'],
            'bank_account_id' => ['nullable', 'exists:accounts,id'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'narration' => ['nullable', 'string', 'max:255'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.purchase_id' => ['required_with:allocations', 'exists:purchases,id'],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'min:0.01'],
        ]);

        try {
            Payment::post($data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.payments.index')->with('status', 'Payment recorded.');
    }

    public function cancel(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $payment->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.payments.index')->with('status', 'Payment cancelled.');
    }
}
