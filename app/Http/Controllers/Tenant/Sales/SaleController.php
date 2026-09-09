<?php

namespace App\Http\Controllers\Tenant\Sales;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Agent;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\Sale;
use App\Models\Store;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SaleController extends Controller
{
    public function index(): Response
    {
        $stockByItem = $this->currentStockByItem();

        $items = Item::query()->where('is_active', true)->orderBy('name')
            ->get(['id', 'name', 'unit', 'is_vatable', 'is_stockable', 'barcode'])
            ->map(function (Item $item) use ($stockByItem) {
                $item->current_stock = $item->is_stockable ? round($stockByItem->get($item->id, 0.0), 4) : null;

                return $item;
            });

        return Inertia::render('Tenant/Sales/Index', [
            'sales' => Sale::query()
                ->with(['customer:id,name', 'agent:id,name', 'lines.item:id,name,unit', 'journalVoucher:id,voucher_type,voucher_number'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'mobile_no']),
            'items' => $items,
            'accounts' => Account::query()->orderBy('name')->get(['id', 'code', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'agents' => Agent::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'commission_rate']),
        ]);
    }

    /**
     * Net on-hand quantity per item, across every store, keyed by item_id -
     * exact copy of PosController::currentStockByItem() (same bulk-query
     * approach, same cross-store scope - it is NOT store-scoped despite
     * Sale::post()'s own per-store Item::currentStock($storeId) check at
     * posting time; kept identical to POS deliberately so the item picker
     * on this page and on the POS tiles never show two different numbers
     * for the same item). Used only to annotate the 'items' prop with a
     * `current_stock` figure for Create.vue's item Combobox.
     *
     * @return Collection<int, float>
     */
    private function currentStockByItem(): Collection
    {
        return ItemStockMovement::query()
            ->where('cancelled', false)
            ->get(['item_id', 'quantity', 'movement_type'])
            ->groupBy('item_id')
            ->map(fn (Collection $movements) => (float) $movements->sum(
                fn (ItemStockMovement $movement) => (float) $movement->quantity * $movement->movement_type->direction(),
            ));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'invoice_type' => ['required', 'in:abbreviated,full,pan'],
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
            'agent_id' => ['nullable', 'exists:agents,id'],
            'commission_amount' => ['nullable', 'numeric', 'min:0'],
            'narration' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            // Negative allowed on purpose: a negative-quantity line is how
            // this app models an in-bill return/adjustment line (legacy
            // parity) - Sale::post() reduces revenue/VAT by the (negative)
            // line total and, via Item::recordStockMovement()'s fixed
            // StockMovementType::Sale direction, correctly nets the stock
            // effect back to a restock instead of a sale. Only exactly zero
            // is meaningless and rejected.
            'lines.*.quantity' => ['required', 'numeric', Rule::notIn([0])],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_type' => ['nullable', 'in:percentage,flat'],
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

        $company = CompanySetting::current();

        $prefix = match ($sale->invoice_type) {
            'abbreviated' => $company->sale_abbreviated_prefix,
            'pan' => $company->sale_pan_prefix,
            default => $company->sale_full_prefix,
        };
        $documentNumber = $sale->journalVoucher
            ? "{$prefix}-{$sale->journalVoucher->voucher_number}"
            : "{$prefix}-{$sale->id}";

        // Thermal paper sizes get a lightweight narrow-column receipt layout
        // instead of the full A4/A5 letterhead invoice - dompdf has no
        // built-in 58mm/80mm paper preset, so the width is passed as an
        // explicit [x1, y1, x2, y2] point box (1mm ≈ 2.83pt) with a generous
        // unbounded height for a continuous thermal roll.
        $isThermal = in_array($company->print_paper_size, ['58mm', '80mm'], true);

        $pdf = Pdf::loadView($isThermal ? 'pdf.sale-receipt' : 'pdf.sale', [
            'sale' => $sale,
            'company' => $company,
            'documentNumber' => $documentNumber,
            'documentDate' => $sale->date->format('Y-m-d'),
        ]);

        if ($isThermal) {
            $width = $company->print_paper_size === '58mm' ? 164 : 227;
            $pdf->setPaper([0, 0, $width, 2000]);
        }

        return $pdf->stream("sale-{$sale->id}.pdf");
    }
}
