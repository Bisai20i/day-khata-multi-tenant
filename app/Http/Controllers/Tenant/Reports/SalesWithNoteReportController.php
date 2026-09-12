<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Sale;
use App\Models\Store;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Multi-tenant equivalent of legacy day_khata's `/saleswithnote` and
 * `/saleswithnoteReportDateBetween` routes (reportsController::saleswithnote()
 * / saleswithnoteReportDateBetween(), privilege key `SalesNote`) - confirmed
 * by reading both legacy methods in full plus the
 * `reports.sales.saleswithnoteledger` Blade view they both render.
 *
 * IMPORTANT discrepancy vs. the legacy name: despite being called
 * "sales with note", legacy's SQL applies NO filter on the `note` column at
 * all - both methods select every non-cancelled sale (`where cancel=0`,
 * `sales_records` joined to `customers`), and the Blade view just renders
 * `note` and `chalaniNo` as two extra columns alongside date/bill
 * no/buyer/items, which are blank for most rows. Functionally it is an
 * alternate Sales Ledger whose purpose is exposing those two free-text
 * columns for browsing, not a report restricted to sales that happen to
 * carry a note.
 *
 * This controller deliberately narrows that to only sales with a non-blank
 * `narration` - this app's equivalent of legacy's `note` column; both are
 * optional free-text header fields, distinct from `chalani_number` (legacy
 * `chalaniNo`), which already exists on `Sale` independently. That is a more
 * useful report than reproducing legacy's unfiltered ledger verbatim (which
 * would just be Sales Register with two mostly-empty extra columns) and is
 * what was asked for; flagged here and in the delivering commit/PR so the
 * discrepancy is not silently assumed away.
 */
class SalesWithNoteReportController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;

        $sales = Sale::query()
            ->with(['customer:id,name', 'lines.item:id,name,unit', 'lines.itemUnit:id,name'])
            ->where('status', 'posted')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->whereNotNull('narration')
            ->where('narration', '!=', '')
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return Inertia::render('Tenant/Reports/SalesWithNote', [
            'sales' => $sales->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'date' => $sale->date->toDateString(),
                'invoice_number' => $sale->invoice_number,
                'customer' => $sale->buyer_name ?? $sale->customer?->name,
                'items' => $sale->lines->map(fn ($line) => [
                    'name' => $line->item?->name,
                    'quantity' => Quantity::of($line->quantity)->toString(),
                    // The unit the line was ENTERED in, not the base unit: a
                    // line sold in Boxes printed "2 Piece" before (audit P1).
                    'unit' => $line->itemUnit?->name ?? $line->item?->unit,
                ])->values(),
                'note' => $sale->narration,
                'chalani_number' => $sale->chalani_number,
                'total' => $sale->total,
            ])->values(),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
            'totals' => [
                'count' => $sales->count(),
                'total' => Money::sum($sales->map(fn (Sale $sale) => Money::of($sale->total)))->toString(),
            ],
        ]);
    }

    /**
     * Defaults to the current open fiscal year's date range when no
     * explicit `from`/`to` query params are given, falling back to
     * month-to-date if no fiscal year exists yet. Mirrors the identical
     * helper in every other report controller in this suite.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(Request $request): array
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        if ($from !== '' && $to !== '') {
            return [$from, $to];
        }

        $fiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

        if ($fiscalYear) {
            return [$fiscalYear->start_date->toDateString(), $fiscalYear->end_date->toDateString()];
        }

        return [now()->startOfMonth()->toDateString(), now()->toDateString()];
    }
}
