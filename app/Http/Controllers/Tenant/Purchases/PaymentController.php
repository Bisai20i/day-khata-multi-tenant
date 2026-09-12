<?php

namespace App\Http\Controllers\Tenant\Purchases;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Support\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            // Money leaves through an asset account, so the bank picker only
            // offers accounts filed under Assets rather than the whole chart.
            'bankAccounts' => Account::query()
                ->where(fn ($query) => $query
                    ->whereHas('group.accountHead', fn ($q) => $q->where('name', 'Assets'))
                    ->orWhereHas('subgroup.accountGroup.accountHead', fn ($q) => $q->where('name', 'Assets')))
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'outstandingPurchases' => $this->outstandingPurchases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'payment_mode' => ['required', 'in:cash,bank'],
            'bank_account_id' => ['nullable', 'exists:accounts,id'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'narration' => ['nullable', 'string', 'max:255'],
            'allocations' => ['nullable', 'array'],
            // `distinct` plus Payment::post()'s own aggregation: two rows
            // naming the same bill used to be checked separately against the
            // full outstanding balance and both pass (audit P0-14).
            'allocations.*.purchase_id' => ['required_with:allocations', 'distinct', 'exists:purchases,id'],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'min:0.01', 'decimal:0,2'],
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
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $payment->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.payments.index')->with('status', 'Payment cancelled.');
    }

    /**
     * Bills a payment can still be allocated against.
     *
     * Not only credit purchases: a cash bill that was under-settled, or one a
     * posted return has since reopened, is just as payable. The filter is an
     * exact "is there anything left" on the Money value, not the old
     * `> 0.01` float test that hid a one-paisa residue (audit P0-4).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function outstandingPurchases(): Collection
    {
        return Purchase::query()
            ->where('status', 'posted')
            ->with(['returns', 'paymentAllocations.payment:id,status'])
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (Purchase $purchase): array => [
                'id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'date' => $purchase->date->toDateString(),
                'bill_number' => $purchase->bill_number,
                'total' => $purchase->total,
                'outstanding' => $purchase->outstandingAmount()->toString(),
            ])
            ->filter(fn (array $row): bool => Money::of($row['outstanding'])->isPositive())
            ->values();
    }
}
