<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Http\Controllers\Concerns\ImportsCsv;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\PrintLog;
use App\Models\StockAdjustment;
use App\Models\Store;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockAdjustmentController extends Controller
{
    use ImportsCsv;

    /**
     * Columns of the opening-stock bulk-import template. item is matched by
     * exact name (case-insensitive) - a spreadsheet-friendly identifier, not
     * the internal id. Every row becomes one 'opening'-reason line
     * (direction forced to 'in', same as the manual create form) on a single
     * new StockAdjustment - see importOpeningStock()'s docblock for why
     * that's safe to build from only the rows that individually validate,
     * unlike a journal-voucher-based import that has to balance as a whole.
     *
     * @var list<string>
     */
    private const OPENING_STOCK_IMPORT_COLUMNS = ['item', 'quantity', 'unit_cost_rate', 'remarks'];

    /**
     * The list is bounded by a date range rather than loading every
     * adjustment a tenant has ever posted (audit P3, "stock adjustment
     * pagination"). It defaults to the open fiscal year, which is the window
     * anyone looking at this screen actually cares about; clearing both
     * dates falls back to the whole history.
     */
    public function index(Request $request): Response
    {
        $currentYear = FiscalYear::current();

        $from = $request->string('from')->toString() ?: $currentYear?->start_date?->toDateString();
        $to = $request->string('to')->toString() ?: $currentYear?->end_date?->toDateString();

        return Inertia::render('Tenant/Inventory/StockAdjustments/Index', [
            'stockAdjustments' => StockAdjustment::query()
                // lines.itemUnit (item 7): the alternate unit a line was
                // entered in, if any - the list shows the quantity as
                // entered, not silently in base units.
                ->with(['lines.item:id,name,unit', 'lines.itemUnit:id,name'])
                ->when($from !== null && $from !== '', fn ($query) => $query->whereDate('date', '>=', $from))
                ->when($to !== null && $to !== '', fn ($query) => $query->whereDate('date', '<=', $to))
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'filters' => ['from' => $from, 'to' => $to],
            // Alternate-unit entry (item 7): each item brings its own active
            // units so the create form can offer a picker, same shape
            // PurchaseController::index() already sends.
            'items' => Item::query()->where('is_stockable', true)->orderBy('name')
                ->with(['units' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
                ->get(['id', 'name', 'unit']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(),
            // See PurchaseController::index()'s identical prop for the
            // rationale - the one closed year currently reopened for
            // correction, if any.
            'correctionFiscalYear' => FiscalYear::openForCorrection()?->only(['id', 'name', 'reopen_reason']),
            // Warns before a re-import, which replaces the existing batch
            // rather than adding to it (see importOpeningStock()).
            'hasOpeningStockImport' => StockAdjustment::query()
                ->where('is_opening_import', true)
                ->where('status', 'posted')
                ->exists(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'fiscal_year_id' => ['nullable', 'integer', 'exists:fiscal_years,id'],
            'reason' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            // Null/omitted means the item's own base unit (item 7) - see
            // SaleController::store()'s identical rule for the rationale.
            // StockAdjustment::post()/resolveItemUnit() owns the cross-item
            // ownership check.
            'lines.*.item_unit_id' => ['nullable', 'integer', 'exists:item_units,id'],
            'lines.*.direction' => ['required', 'in:in,out'],
            'lines.*.reason_type' => ['required', 'in:damage,lost,correction,found,opening,other'],
            // `decimal:0,4` rejects an over-precise quantity here with a
            // clean field error instead of letting App\Casts\Decimal throw
            // further down. Audit P0-5: 0.00004 used to be charged for and
            // then stored as 0.0000.
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001', 'decimal:0,4'],
            'lines.*.unit_cost_rate' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'lines.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            StockAdjustment::post(
                Arr::except($data, ['lines']),
                $data['lines'],
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        } catch (AuthorizationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.stock-adjustments.index')->with('status', 'Stock adjustment posted.');
    }

    public function cancel(Request $request, StockAdjustment $stock_adjustment): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $stock_adjustment->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.stock-adjustments.index')->with('status', 'Stock adjustment cancelled.');
    }

    /**
     * Streams a printable PDF inline (not a forced download), so it opens in
     * a new browser tab from a plain anchor link on the Index page - same
     * pattern as SaleController::print()/PurchaseController::print(). A
     * stock adjustment has no invoice-style numbering sequence of its own
     * (see this class's docblock - it never touches the ledger), so the
     * document number is simply its own id, matching QuotationController::
     * print()'s fallback for a quotation with no reference_number.
     */
    public function print(Request $request, StockAdjustment $stock_adjustment): HttpResponse
    {
        $stock_adjustment->load(['store', 'lines.item', 'lines.itemUnit']);

        $pdf = Pdf::loadView('pdf.stock-adjustment', [
            'stockAdjustment' => $stock_adjustment,
            'company' => CompanySetting::current(),
            'documentNumber' => "ADJ-{$stock_adjustment->id}",
            'documentDate' => $stock_adjustment->date->format('Y-m-d'),
            // Records this print for the audit trail (CONTRACTS C9), same as
            // every other printable document - see QuotationController::
            // print() for the identical pattern.
            'copyNumber' => PrintLog::record($stock_adjustment, $request->user()),
        ]);

        return $pdf->stream("stock-adjustment-{$stock_adjustment->id}.pdf");
    }

    /**
     * Downloads a blank CSV template for the opening-stock bulk import, plus
     * one example row.
     */
    public function openingStockTemplate(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::OPENING_STOCK_IMPORT_COLUMNS);
            fputcsv($handle, ['Bottled Water 1L', '100', '15.00', 'Opening stock as of setup']);
            fclose($handle);
        }, 'opening-stock-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Bulk sets each item's starting quantity via a single new
     * StockAdjustment - a variant of the exact same StockAdjustment::post()
     * used by the manual create form, not a parallel stock-movement
     * pathway, with every line forced to reason_type=opening (post() itself
     * also forces direction='in' regardless of what's passed, same
     * invariant as the manual form). Rows are matched by item name
     * (case-insensitive) since a spreadsheet operator can't reasonably be
     * expected to know internal item ids.
     *
     * Unlike the Journal-Voucher-based Opening Balance import, an opening
     * stock line has no cross-row balance requirement (every line is an
     * independent 'in' movement) - so, matching Customer/Supplier/Item
     * import's own rigor, an invalid row is simply skipped and reported
     * rather than aborting the whole file: the StockAdjustment header ends
     * up with only the lines that validated.
     *
     * Re-importing **replaces** the previous opening batch instead of
     * stacking a second set of opening quantities on top of it (audit P1),
     * and posts the one ledger entry opening stock gets - see
     * StockAdjustment::postOpeningImport() for both. The UI warns before the
     * upload so the replacement is never a surprise.
     */
    public function importOpeningStock(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
            'date' => ['required', 'date'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'fiscal_year_id' => ['nullable', 'integer', 'exists:fiscal_years,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $rows = $this->parseCsvRows($request->file('file'));

        if ($rows === null || ! array_key_exists('item', $rows[0])) {
            return back()->withErrors(['file' => 'That file could not be read. Make sure it matches the downloaded template and has an "item" column.'])->withInput();
        }

        $items = Item::all(['id', 'name', 'is_stockable'])->keyBy(fn (Item $item) => strtolower($item->name));

        $seenItems = [];
        $skipped = [];
        $lines = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +1 for the 0-based index, +1 for the header row.
            $itemName = trim($row['item'] ?? '');

            if ($itemName === '') {
                $skipped[] = ['row' => $rowNumber, 'name' => $itemName, 'reason' => 'An item name is required.'];

                continue;
            }

            $item = $items->get(strtolower($itemName));

            if (! $item) {
                $skipped[] = ['row' => $rowNumber, 'name' => $itemName, 'reason' => "Unknown item \"{$itemName}\"."];

                continue;
            }

            if (! $item->is_stockable) {
                $skipped[] = ['row' => $rowNumber, 'name' => $itemName, 'reason' => "\"{$itemName}\" is not a stockable item."];

                continue;
            }

            $itemKey = strtolower($itemName);

            if (isset($seenItems[$itemKey])) {
                $skipped[] = ['row' => $rowNumber, 'name' => $itemName, 'reason' => 'Duplicate item already used earlier in this file.'];

                continue;
            }

            $validator = Validator::make([
                'quantity' => $row['quantity'] ?? '',
                'unit_cost_rate' => $row['unit_cost_rate'] ?? '',
            ], [
                'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
                'unit_cost_rate' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            ]);

            if ($validator->fails()) {
                $skipped[] = ['row' => $rowNumber, 'name' => $itemName, 'reason' => $validator->errors()->first()];

                continue;
            }

            $seenItems[$itemKey] = true;

            $unitCostRate = trim($row['unit_cost_rate'] ?? '');

            $lines[] = [
                'item_id' => $item->id,
                'direction' => 'in',
                'reason_type' => 'opening',
                // Kept as the CSV's own text so App\Support\Money\Quantity
                // reads the exact decimal the operator typed; a float cast
                // here is what audit P0-5 was about.
                'quantity' => trim((string) $row['quantity']),
                'unit_cost_rate' => $unitCostRate === '' ? null : $unitCostRate,
                'remarks' => trim($row['remarks'] ?? '') === '' ? null : trim($row['remarks']),
            ];
        }

        $imported = 0;
        $replaced = null;

        if ($lines !== []) {
            try {
                ['replaced' => $replaced] = StockAdjustment::postOpeningImport(
                    $request->only(['date', 'store_id', 'fiscal_year_id', 'reason']) + ['note' => 'Opening stock import'],
                    $lines,
                    $request->user(),
                );
            } catch (InvalidArgumentException $e) {
                return back()->withErrors(['lines' => $e->getMessage()])->withInput();
            } catch (AuthorizationException $e) {
                return back()->withErrors(['reason' => $e->getMessage()])->withInput();
            }

            $imported = count($lines);
        }

        $total = $imported + count($skipped);
        $status = "Imported opening stock for {$imported} of {$total} item(s).";

        if ($replaced !== null) {
            $status .= " The previous opening stock import (#{$replaced->id}) was cancelled and replaced.";
        }

        return redirect()->route('tenant.stock-adjustments.index')
            ->with('status', $status)
            ->with('importResult', ['imported' => $imported, 'skipped' => $skipped, 'replaced' => $replaced?->id]);
    }
}
