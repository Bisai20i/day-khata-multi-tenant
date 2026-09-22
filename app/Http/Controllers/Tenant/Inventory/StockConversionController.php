<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Enums\StockConversionType;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Item;
use App\Models\PrintLog;
use App\Models\StockConversion;
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

class StockConversionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Inventory/StockConversions/Index', [
            'stockConversions' => StockConversion::query()
                ->with(['lines.item:id,name,unit', 'store:id,name'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'items' => Item::query()->where('is_stockable', true)->orderBy('name')->get(['id', 'name', 'unit']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:production,refining,repackaging'],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'input_lines' => ['required', 'array', 'min:1'],
            'input_lines.*.item_id' => ['required', 'exists:items,id'],
            'input_lines.*.quantity' => ['required', 'numeric', 'min:0.0001', 'decimal:0,4'],
            'input_lines.*.unit_cost_rate' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'input_lines.*.remarks' => ['nullable', 'string', 'max:255'],
            'output_lines' => ['required', 'array', 'min:1'],
            'output_lines.*.item_id' => ['required', 'exists:items,id'],
            'output_lines.*.quantity' => ['required', 'numeric', 'min:0.0001', 'decimal:0,4'],
            'output_lines.*.unit_cost_rate' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'output_lines.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            StockConversion::post(
                Arr::only($data, ['type', 'date', 'note', 'store_id']),
                $data['input_lines'],
                $data['output_lines'],
                $request->user(),
            );
        } catch (InvalidArgumentException|AuthorizationException $e) {
            // AuthorizationException as well as InvalidArgumentException:
            // StockConversion::post() now runs the date through
            // ClosedFiscalYearGuard (CONTRACTS C4), which throws the former
            // when a non-admin aims at a reopened closed year. Letting it
            // escape would render a 403 page instead of a field error.
            return back()->withErrors(['input_lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.stock-conversions.index')->with('status', 'Stock conversion posted.');
    }

    public function cancel(Request $request, StockConversion $stock_conversion): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $stock_conversion->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.stock-conversions.index')->with('status', 'Stock conversion cancelled.');
    }

    /**
     * Streams a printable PDF inline (not a forced download), so it opens in
     * a new browser tab from a plain anchor link on the Index page - same
     * pattern as SaleController::print()/PurchaseController::print(). A
     * stock conversion has no invoice-style numbering sequence of its own
     * (see StockConversion's own docblock - it never touches the ledger), so
     * the document number is simply its own id, matching
     * QuotationController::print()'s fallback for a quotation with no
     * reference_number. Section labels mirror Create.vue's own
     * sectionLabels map so the printed document reads the same way the
     * create form did.
     */
    public function print(Request $request, StockConversion $stock_conversion): HttpResponse
    {
        $stock_conversion->load(['store', 'lines.item']);

        $sectionLabels = match ($stock_conversion->type) {
            StockConversionType::Production => ['input' => 'Raw materials consumed', 'output' => 'Finished good produced'],
            StockConversionType::Refining => ['input' => 'Input material consumed', 'output' => 'Refined output produced'],
            StockConversionType::Repackaging => ['input' => 'Items consumed', 'output' => 'Items produced'],
        };

        $pdf = Pdf::loadView('pdf.stock-conversion', [
            'stockConversion' => $stock_conversion,
            'inputLines' => $stock_conversion->lines->where('direction', 'out')->values(),
            'outputLines' => $stock_conversion->lines->where('direction', 'in')->values(),
            'inputLabel' => $sectionLabels['input'],
            'outputLabel' => $sectionLabels['output'],
            'company' => CompanySetting::current(),
            'documentNumber' => "CNV-{$stock_conversion->id}",
            'documentDate' => $stock_conversion->date->format('Y-m-d'),
            // Records this print for the audit trail (CONTRACTS C9), same as
            // every other printable document - see QuotationController::
            // print() for the identical pattern.
            'copyNumber' => PrintLog::record($stock_conversion, $request->user()),
        ]);

        return $pdf->stream("stock-conversion-{$stock_conversion->id}.pdf");
    }
}
