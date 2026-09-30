<?php

namespace App\Http\Controllers\Tenant\Purchases;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Rules\AccountUnderHead;
use App\Support\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PaymentController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Tenant/Purchases/Payments/Index', [
            // Paginated like receipts (flags G-17): the whole payment history
            // used to load on every visit.
            'payments' => Payment::query()
                ->with(['supplier:id,name', 'allocations:id,payment_id,amount'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            // Money leaves through an asset account, so the bank picker only
            // offers accounts filed under Assets rather than the whole chart.
            'bankAccounts' => Account::query()
                ->where(fn ($query) => $query
                    ->whereHas('group.accountHead', fn ($q) => $q->where('name', 'Assets'))
                    ->orWhereHas('subgroup.accountGroup.accountHead', fn ($q) => $q->where('name', 'Assets')))
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            // Only for the supplier being paid, and only when the form asks
            // for it (a partial reload with supplier_id); it used to price
            // every posted bill in the system on every page load.
            'outstandingPurchases' => Inertia::optional(fn () => $this->outstandingPurchases($request->integer('supplier_id') ?: null)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'payment_mode' => ['required', 'in:cash,bank'],
            'bank_account_id' => ['nullable', 'exists:accounts,id', new AccountUnderHead('Assets')],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'narration' => ['nullable', 'string', 'max:255'],
            'allocations' => ['nullable', 'array'],
            // `distinct` plus Payment::post()'s own aggregation: two rows
            // naming the same bill used to be checked separately against the
            // full outstanding balance and both pass (audit P0-14).
            'allocations.*.purchase_id' => ['required_with:allocations', 'distinct', 'exists:purchases,id'],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'min:0.01', 'decimal:0,2'],
        ]);

        $this->assertAllocationsNotBefore($data);

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
    private function outstandingPurchases(?int $supplierId): Collection
    {
        if ($supplierId === null) {
            return collect();
        }

        $purchases = Purchase::query()
            ->where('status', 'posted')
            ->where('supplier_id', $supplierId)
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        // Two queries for all of them, not two per bill (flags G-17).
        $outstanding = Purchase::outstandingAmounts($purchases);

        return $purchases
            ->map(fn (Purchase $purchase): array => [
                'id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'date' => $purchase->date->toDateString(),
                'purchase_number' => $purchase->purchase_number,
                'bill_number' => $purchase->bill_number,
                'total' => $purchase->total,
                'outstanding' => $outstanding[$purchase->id]->toString(),
            ])
            ->filter(fn (array $row): bool => Money::of($row['outstanding'])->isPositive())
            ->values();
    }

    /**
     * Puts "dated after this payment" on the allocation row itself, so the
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
        $documentDates = Purchase::query()
            ->whereIn('id', array_column($allocations, 'purchase_id'))
            ->get(['id', 'date'])
            ->mapWithKeys(fn (Purchase $document) => [$document->id => $document->date->toDateString()]);

        $errors = [];

        foreach ($allocations as $index => $allocation) {
            $documentDate = $documentDates->get((int) $allocation['purchase_id']);

            if ($documentDate !== null && $documentDate > $date) {
                $errors["allocations.{$index}.purchase_id"] = "This bill is dated {$documentDate}, after this payment ({$date}).";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
