<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\SaleLine;
use App\Models\Store;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Item-level counterpart to the invoice-level Sales Register: every SaleLine
 * of every posted Sale in a date range, aggregated by item, so a user can
 * see what is actually moving rather than one row per invoice. Cancelled and
 * out-of-range sales are excluded entirely, matching the register above it.
 *
 * Quantities are in BASE units (`quantity x unit_conversion_factor`), not
 * whatever unit each line happened to be entered in. Selling 2 Boxes of 12
 * and 3 loose pieces is 27 pieces, not "5" (audit P1: alternate units were
 * being added to base units). For the same reason the grand total is a
 * per-unit breakdown rather than one number - adding Kilograms to Pieces
 * produces a figure nobody can use.
 *
 * Audit T15-6: legacy's viewSalesReportItemWise() requires a specific item
 * and shows nothing but a per-line transaction ledger (date, invoice
 * number, customer, rate, quantity, vatable flag) - it never aggregates.
 * This report keeps its own (more useful) all-items aggregate as the
 * default view, but when an `item_id` is picked it additionally returns
 * that item's raw per-line rows alongside the aggregate, so "show me every
 * sale of item X, at what rate, to which customer" has an answer here too.
 */
class ItemWiseSalesReportController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;
        $itemId = $request->integer('item_id') ?: null;

        $lines = SaleLine::query()
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->join('items', 'items.id', '=', 'sale_lines.item_id')
            ->where('sales.status', 'posted')
            ->whereDate('sales.date', '>=', $from)
            ->whereDate('sales.date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('sales.store_id', $storeId))
            ->get([
                'sale_lines.item_id as item_id',
                'sale_lines.sale_id as document_id',
                'sale_lines.quantity as quantity',
                'sale_lines.unit_conversion_factor as unit_conversion_factor',
                'sale_lines.line_total as line_total',
                'items.name as name',
                'items.unit as unit',
            ]);

        $items = $this->aggregateByItem($lines);

        return Inertia::render('Tenant/Reports/ItemWiseSales', [
            'items' => $items,
            'totals' => [
                'total_value' => Money::sum($items->map(fn (array $row) => Money::of($row['total_value'])))->toString(),
                'quantities' => $this->quantitiesByUnit($items),
            ],
            'lines' => $itemId ? $this->itemLineDetail($itemId, $from, $to, $storeId) : [],
            'itemsList' => Item::query()->orderBy('name')->get(['id', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
            'itemId' => $itemId,
        ]);
    }

    /**
     * The raw per-line transaction ledger for a single item, i.e. legacy's
     * entire viewSalesReportItemWise() view - one row per SaleLine, not
     * folded into any aggregate, so a shopkeeper can see exactly which
     * invoice, at what rate, to which customer.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function itemLineDetail(int $itemId, string $from, string $to, ?int $storeId): Collection
    {
        return SaleLine::query()
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('sales.status', 'posted')
            ->where('sale_lines.item_id', $itemId)
            ->whereDate('sales.date', '>=', $from)
            ->whereDate('sales.date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('sales.store_id', $storeId))
            ->orderBy('sales.date')
            ->orderBy('sales.id')
            ->get([
                'sales.date as date',
                'sales.invoice_number as document_number',
                'customers.name as party_name',
                'sale_lines.rate as rate',
                'sale_lines.quantity as quantity',
                'sale_lines.line_total as line_total',
                'sale_lines.vatable as vatable',
            ])
            ->map(fn (Model $line) => [
                'date' => (string) $line->date,
                'document_number' => $line->document_number,
                'party_name' => $line->party_name,
                'rate' => Quantity::of($line->rate)->toString(),
                'quantity' => Quantity::of($line->quantity)->toString(),
                'line_total' => Money::of($line->line_total)->toString(),
                'vatable' => (bool) $line->vatable,
            ])
            ->values();
    }

    /**
     * @param  Collection<int, Model>  $lines
     * @return Collection<int, array<string, mixed>>
     */
    private function aggregateByItem(Collection $lines): Collection
    {
        $byItem = [];

        foreach ($lines as $line) {
            $itemId = (int) $line->item_id;

            $byItem[$itemId] ??= [
                'item_id' => $itemId,
                'name' => $line->name,
                'unit' => $line->unit,
                'quantity' => Quantity::zero(),
                'value' => Money::zero(),
                'documents' => [],
            ];

            $byItem[$itemId]['quantity'] = $byItem[$itemId]['quantity']->plus(
                Quantity::round(
                    Quantity::of($line->quantity)->toBigDecimal()
                        ->multipliedBy(Quantity::of($line->unit_conversion_factor ?? '1')->toBigDecimal())
                )
            );
            $byItem[$itemId]['value'] = $byItem[$itemId]['value']->plus(Money::of($line->line_total));
            $byItem[$itemId]['documents'][(int) $line->document_id] = true;
        }

        return collect($byItem)
            ->map(fn (array $row) => [
                'item_id' => $row['item_id'],
                'name' => $row['name'],
                'unit' => $row['unit'],
                'total_quantity' => $row['quantity']->toString(),
                'total_value' => $row['value']->toString(),
                'transaction_count' => count($row['documents']),
            ])
            ->sort(fn (array $a, array $b) => Money::of($b['total_value'])->compareTo(Money::of($a['total_value'])))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array{unit: string, quantity: string}>
     */
    private function quantitiesByUnit(Collection $items): array
    {
        $byUnit = [];

        foreach ($items as $row) {
            $unit = (string) ($row['unit'] ?? '');
            $byUnit[$unit] = ($byUnit[$unit] ?? Quantity::zero())->plus(Quantity::of($row['total_quantity']));
        }

        $totals = [];

        foreach ($byUnit as $unit => $quantity) {
            $totals[] = ['unit' => $unit, 'quantity' => $quantity->toString()];
        }

        usort($totals, fn (array $a, array $b) => strcasecmp($a['unit'], $b['unit']));

        return $totals;
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
