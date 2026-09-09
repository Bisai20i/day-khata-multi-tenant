<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Http\Controllers\Concerns\ImportsCsv;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\StockAdjustment;
use App\Models\Store;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function index(): Response
    {
        return Inertia::render('Tenant/Inventory/StockAdjustments/Index', [
            'stockAdjustments' => StockAdjustment::query()
                ->with(['lines.item:id,name,unit'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'items' => Item::query()->where('is_stockable', true)->orderBy('name')->get(['id', 'name', 'unit']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(),
            // See PurchaseController::index()'s identical prop for the
            // rationale - the one closed year currently reopened for
            // correction, if any.
            'correctionFiscalYear' => FiscalYear::openForCorrection()?->only(['id', 'name', 'reopen_reason']),
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
            'lines.*.direction' => ['required', 'in:in,out'],
            'lines.*.reason_type' => ['required', 'in:damage,lost,correction,found,opening,other'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_cost_rate' => ['nullable', 'numeric', 'min:0'],
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
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $stock_adjustment->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.stock-adjustments.index')->with('status', 'Stock adjustment cancelled.');
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
                'quantity' => ['required', 'numeric', 'gt:0'],
                'unit_cost_rate' => ['nullable', 'numeric', 'min:0'],
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
                'quantity' => (float) $row['quantity'],
                'unit_cost_rate' => $unitCostRate === '' ? null : (float) $unitCostRate,
                'remarks' => trim($row['remarks'] ?? '') === '' ? null : trim($row['remarks']),
            ];
        }

        $imported = 0;

        if ($lines !== []) {
            try {
                StockAdjustment::post(
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

        return redirect()->route('tenant.stock-adjustments.index')
            ->with('status', "Imported opening stock for {$imported} of {$total} item(s).")
            ->with('importResult', ['imported' => $imported, 'skipped' => $skipped]);
    }
}
