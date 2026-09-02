<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\PurchaseLine;
use App\Models\Store;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Item-level counterpart to the invoice-level Purchase Register: aggregates
 * every PurchaseLine of every posted Purchase in a date range by item, so a
 * user can see what's actually being bought (total quantity/value per item)
 * rather than one row per invoice. Cancelled purchases and out-of-range
 * purchases are excluded entirely, matching the VAT-book convention
 * elsewhere in this report suite.
 */
class ItemWisePurchaseReportController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;

        $rows = PurchaseLine::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->join('items', 'items.id', '=', 'purchase_lines.item_id')
            ->where('purchases.status', 'posted')
            ->whereBetween('purchases.date', [$from, $to])
            ->when($storeId, fn ($query) => $query->where('purchases.store_id', $storeId))
            ->groupBy('purchase_lines.item_id', 'items.name', 'items.unit')
            ->orderByDesc('total_value')
            ->selectRaw('purchase_lines.item_id as item_id')
            ->selectRaw('items.name as name')
            ->selectRaw('items.unit as unit')
            ->selectRaw('sum(purchase_lines.quantity) as total_quantity')
            ->selectRaw('sum(purchase_lines.line_total) as total_value')
            ->selectRaw('count(distinct purchase_lines.purchase_id) as transaction_count')
            ->get();

        $items = $rows->map(fn (PurchaseLine $row) => [
            'item_id' => (int) $row->item_id,
            'name' => $row->name,
            'unit' => $row->unit,
            'total_quantity' => round((float) $row->total_quantity, 4),
            'total_value' => round((float) $row->total_value, 2),
            'transaction_count' => (int) $row->transaction_count,
        ])->values();

        return Inertia::render('Tenant/Reports/ItemWisePurchase', [
            'items' => $items,
            'totals' => [
                'total_quantity' => round((float) $items->sum('total_quantity'), 4),
                'total_value' => round((float) $items->sum('total_value'), 2),
            ],
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
        ]);
    }

    /**
     * Defaults to the current open fiscal year's date range when no
     * explicit `from`/`to` query params are given, falling back to
     * month-to-date if no fiscal year exists yet.
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
