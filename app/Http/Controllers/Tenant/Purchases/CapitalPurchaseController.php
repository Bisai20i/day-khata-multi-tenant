<?php

namespace App\Http\Controllers\Tenant\Purchases;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CapitalPurchase;
use App\Models\CompanySetting;
use App\Models\Store;
use App\Models\Supplier;
use App\Support\Billing\BillingException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class CapitalPurchaseController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Purchases/CapitalPurchases/Index', [
            'capitalPurchases' => CapitalPurchase::query()
                ->with(['supplier:id,name,tpin', 'lines.account:id,code,name', 'journalVoucher:id,voucher_number'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'tpin']),
            'accounts' => Account::query()->orderBy('name')->get(['id', 'code', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'defaultVatRate' => (string) (CompanySetting::current()->default_vat_rate ?? '13.00'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'supplier_pan' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:capital,service'],
            'bill_number' => ['nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'narration' => ['nullable', 'string', 'max:255'],
            'payment_mode' => ['required', 'in:cash,bank,partial,credit'],
            'bank_account_id' => ['nullable', 'exists:accounts,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'cash_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'bank_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'expected_total' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'exists:accounts,id'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'lines.*.vatable' => ['nullable', 'boolean'],
            'lines.*.narration' => ['nullable', 'string', 'max:255'],
        ]);

        // The model holds the authoritative rule (and the unique index behind
        // it) because only it can check inside the posting transaction; this
        // rule catches the ordinary case with a field-level message before the
        // form is even submitted twice.
        $this->assertBillNumberIsNew($data);

        try {
            $capitalPurchase = CapitalPurchase::post($data, $data['lines'], $request->user());
        } catch (BillingException $e) {
            // C8: the browser previewed a total with money.js and sent it back.
            // A difference means the bill on screen was not the bill being
            // saved, so the save is refused rather than booking another amount.
            throw ValidationException::withMessages(
                $e->reason === BillingException::REASON_TOTAL_MISMATCH
                    ? ['expected_total' => 'The bill total changed. Please review it before saving.']
                    : ['lines' => $e->getMessage()]
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.capital-purchases.index')
            ->with('status', 'Capital purchase posted.')
            ->with('created', [
                'type' => 'capital-purchase',
                'id' => $capitalPurchase->id,
            ]);
    }

    public function cancel(Request $request, CapitalPurchase $capitalPurchase): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $capitalPurchase->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.capital-purchases.index')->with('status', 'Capital purchase cancelled.');
    }

    /**
     * @param  array{supplier_id?: int|null, bill_number?: string|null}  $data
     */
    private function assertBillNumberIsNew(array $data): void
    {
        $guard = CapitalPurchase::billNumberGuard(
            isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            $data['bill_number'] ?? null,
        );

        if ($guard === null) {
            return;
        }

        if (CapitalPurchase::query()->where('bill_number_guard', $guard)->exists()) {
            throw ValidationException::withMessages([
                'bill_number' => 'This bill number has already been entered for this supplier.',
            ]);
        }
    }
}
