<?php

namespace App\Http\Controllers\Tenant\Purchases;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\Supplier;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PurchaseController extends Controller
{
    /**
     * Listing is server-side filtered (date range + supplier) and paginated
     * - same `when()`/`paginate()->withQueryString()` shape
     * Central\Tenants\TenantController::index() established, so it stays
     * consistent across the app rather than loading every purchase
     * unfiltered (a real usability problem once invoice history grows).
     */
    public function index(Request $request): Response
    {
        $from = $request->filled('from') ? $request->string('from')->toString() : null;
        $to = $request->filled('to') ? $request->string('to')->toString() : null;
        $supplierId = $request->filled('supplier_id') ? (int) $request->input('supplier_id') : null;

        $purchases = Purchase::query()
            ->with(['supplier:id,name', 'lines.item:id,name,unit', 'journalVoucher:id,voucher_number'])
            ->when($from, fn ($query, string $from) => $query->whereDate('date', '>=', $from))
            ->when($to, fn ($query, string $to) => $query->whereDate('date', '<=', $to))
            ->when($supplierId, fn ($query, int $supplierId) => $query->where('supplier_id', $supplierId))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Tenant/Purchases/Index', [
            'purchases' => $purchases,
            'filters' => [
                'from' => $from,
                'to' => $to,
                'supplier_id' => $supplierId,
            ],
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'mobile_no']),
            'items' => Item::query()->orderBy('name')
                ->with(['units' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
                ->get(['id', 'name', 'unit', 'is_vatable', 'is_stockable', 'barcode']),
            'accounts' => Account::query()->orderBy('name')->get(['id', 'code', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            // The one closed year currently reopened for correction, if
            // any - lets Create.vue offer it as the only non-current
            // fiscal-year option, per the locked design decision (see
            // ClosedFiscalYearGuard's docblock). Null hides the picker
            // entirely, same as before this feature existed.
            'correctionFiscalYear' => FiscalYear::openForCorrection()?->only(['id', 'name', 'reopen_reason']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'bill_number' => ['nullable', 'string', 'max:255'],
            'pan_number' => ['nullable', 'string', 'max:50'],
            'chalani_number' => ['nullable', 'string', 'max:100'],
            'date' => ['required', 'date'],
            'payment_mode' => ['required', 'in:cash,bank,partial,credit'],
            'bank_account_id' => ['nullable', 'exists:accounts,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', 'in:percentage,flat'],
            'vat_rate' => ['nullable', 'numeric', 'min:0'],
            'cash_amount' => ['nullable', 'numeric', 'min:0'],
            'bank_amount' => ['nullable', 'numeric', 'min:0'],
            'tds_account_id' => ['nullable', 'exists:accounts,id'],
            'tds_amount' => ['nullable', 'numeric', 'min:0'],
            'narration' => ['nullable', 'string', 'max:255'],
            'fiscal_year_id' => ['nullable', 'integer', 'exists:fiscal_years,id'],
            'reason' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            // Null/omitted means the item's own base unit - see
            // SaleController::store()'s identical rule for the full
            // rationale (Purchase::post() owns the cross-item ownership
            // check).
            'lines.*.item_unit_id' => ['nullable', 'integer', 'exists:item_units,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_type' => ['nullable', 'in:percentage,flat'],
        ]);

        try {
            Purchase::post($data, $data['lines'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        } catch (AuthorizationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.purchases.index')->with('status', 'Purchase posted.');
    }

    public function cancel(Request $request, Purchase $purchase): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $purchase->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.purchases.index')->with('status', 'Purchase cancelled.');
    }

    /**
     * Streams a printable PDF purchase bill inline (not a forced download), so
     * it opens in a new browser tab from a plain anchor link on the Index page.
     */
    public function print(Purchase $purchase): HttpResponse
    {
        $purchase->load(['supplier', 'bankAccount', 'lines.item', 'journalVoucher']);

        $company = CompanySetting::current();

        $documentNumber = $purchase->journalVoucher
            ? "{$company->purchase_prefix}-{$purchase->journalVoucher->voucher_number}"
            : "{$company->purchase_prefix}-{$purchase->id}";

        return Pdf::loadView('pdf.purchase', [
            'purchase' => $purchase,
            'company' => $company,
            'documentNumber' => $documentNumber,
            'documentDate' => $purchase->date->format('Y-m-d'),
        ])->stream("purchase-{$purchase->id}.pdf");
    }
}
