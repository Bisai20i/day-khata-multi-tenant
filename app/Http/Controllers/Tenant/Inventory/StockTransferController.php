<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Item;
use App\Models\PrintLog;
use App\Models\StockTransfer;
use App\Models\Store;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class StockTransferController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Inventory/StockTransfers/Index', [
            'stockTransfers' => StockTransfer::query()
                ->with(['lines.item:id,name,unit', 'fromStore:id,name', 'toStore:id,name'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'items' => Item::query()->where('is_stockable', true)->orderBy('name')->get(['id', 'name', 'unit']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'from_store_id' => ['required', 'integer', 'exists:stores,id'],
            'to_store_id' => ['required', 'integer', 'exists:stores,id', 'different:from_store_id'],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001', 'decimal:0,4'],
            'lines.*.unit_cost_rate' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'lines.*.remarks' => ['nullable', 'string', 'max:255'],
        ], [
            'to_store_id.different' => 'The source and destination store must be different.',
        ]);

        try {
            StockTransfer::post(
                Arr::except($data, ['lines']),
                $data['lines'],
                $request->user(),
            );
        } catch (InvalidArgumentException|AuthorizationException $e) {
            // AuthorizationException as well as InvalidArgumentException:
            // StockTransfer::post() now runs the date through
            // ClosedFiscalYearGuard (CONTRACTS C4), which throws the former
            // when a non-admin aims at a reopened closed year. Letting it
            // escape would render a 403 page instead of a field error.
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.stock-transfers.index')->with('status', 'Stock transfer posted.');
    }

    public function cancel(Request $request, StockTransfer $stock_transfer): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $stock_transfer->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.stock-transfers.index')->with('status', 'Stock transfer cancelled.');
    }

    /**
     * Streams a printable PDF inline (not a forced download), so it opens in
     * a new browser tab from a plain anchor link on the Index page - same
     * pattern as SaleController::print()/PurchaseController::print(). A
     * stock transfer has no invoice-style numbering sequence of its own (see
     * this class's docblock - it never touches the ledger), so the document
     * number is simply its own id, matching QuotationController::print()'s
     * fallback for a quotation with no reference_number.
     */
    public function print(Request $request, StockTransfer $stock_transfer): HttpResponse
    {
        $stock_transfer->load(['fromStore', 'toStore', 'lines.item']);

        $pdf = Pdf::loadView('pdf.stock-transfer', [
            'stockTransfer' => $stock_transfer,
            'company' => CompanySetting::current(),
            'documentNumber' => "TRF-{$stock_transfer->id}",
            'documentDate' => $stock_transfer->date->format('Y-m-d'),
            // Records this print for the audit trail (CONTRACTS C9), same as
            // every other printable document - see QuotationController::
            // print() for the identical pattern.
            'copyNumber' => PrintLog::record($stock_transfer, $request->user()),
        ]);

        return $pdf->stream("stock-transfer-{$stock_transfer->id}.pdf");
    }
}
