<?php

namespace App\Http\Controllers\Tenant\Purchases;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Store;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PurchaseReturnController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Purchases/Returns/Index', [
            'returns' => PurchaseReturn::query()
                ->with(['purchase.supplier:id,name', 'lines.purchaseLine.item:id,name,unit', 'refundAccount:id,code,name'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'purchases' => Purchase::query()
                ->where('status', 'posted')
                ->with(['supplier:id,name', 'lines.item:id,name,unit'])
                ->orderByDesc('date')
                ->get(),
            'accounts' => Account::query()->orderBy('name')->get(['id', 'code', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'purchase_id' => ['required', 'exists:purchases,id'],
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'refund_account_id' => ['nullable', 'exists:accounts,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_line_id' => ['required', 'exists:purchase_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        ]);

        try {
            PurchaseReturn::post($data, $data['lines'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.purchase-returns.index')->with('status', 'Purchase return posted.');
    }

    public function cancel(Request $request, PurchaseReturn $purchaseReturn): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $purchaseReturn->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.purchase-returns.index')->with('status', 'Purchase return cancelled.');
    }

    /**
     * Streams a printable PDF debit note inline (not a forced download), so
     * it opens in a new browser tab from a plain anchor link on the Index
     * page - mirrors SaleController::print()'s exact pattern.
     */
    public function print(PurchaseReturn $purchaseReturn): HttpResponse
    {
        $purchaseReturn->load(['purchase.supplier', 'lines.purchaseLine.item', 'journalVoucher', 'refundAccount']);

        $documentNumber = $purchaseReturn->journalVoucher
            ? "PR-{$purchaseReturn->journalVoucher->voucher_number}"
            : "PR-{$purchaseReturn->id}";

        return Pdf::loadView('pdf.purchase-return', [
            'purchaseReturn' => $purchaseReturn,
            'company' => CompanySetting::current(),
            'documentNumber' => $documentNumber,
            'documentDate' => $purchaseReturn->date->format('Y-m-d'),
        ])->stream("purchase-return-{$purchaseReturn->id}.pdf");
    }
}
