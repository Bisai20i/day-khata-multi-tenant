<?php

namespace App\Http\Controllers\Tenant\Purchases;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CapitalPurchase;
use App\Models\Store;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class CapitalPurchaseController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Purchases/CapitalPurchases/Index', [
            'capitalPurchases' => CapitalPurchase::query()
                ->with(['supplier:id,name', 'lines.account:id,code,name', 'journalVoucher:id,voucher_number'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'accounts' => Account::query()->orderBy('name')->get(['id', 'code', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'type' => ['required', 'in:capital,service'],
            'date' => ['required', 'date'],
            'narration' => ['nullable', 'string', 'max:255'],
            'payment_mode' => ['required', 'in:cash,bank,partial,credit'],
            'bank_account_id' => ['nullable', 'exists:accounts,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'cash_amount' => ['nullable', 'numeric', 'min:0'],
            'bank_amount' => ['nullable', 'numeric', 'min:0'],
            'vat_amount' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'exists:accounts,id'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'lines.*.narration' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            CapitalPurchase::post($data, $data['lines'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.capital-purchases.index')->with('status', 'Capital purchase posted.');
    }

    public function cancel(Request $request, CapitalPurchase $capitalPurchase): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $capitalPurchase->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.capital-purchases.index')->with('status', 'Capital purchase cancelled.');
    }
}
