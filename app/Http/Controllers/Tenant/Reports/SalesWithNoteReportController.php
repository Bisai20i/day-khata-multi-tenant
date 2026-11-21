<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Exports\SalesWithNoteExport;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Sale;
use App\Models\Store;
use App\Support\Branches\BranchFilter;
use App\Support\Branches\BranchStock;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
 * `narration` - this app's equivalent of legacy's `note` column (the free
 * text typed in the Note box on the sale form; a sale saved without one
 * stores null). Both are optional free-text header fields, distinct from
 * `chalani_number` (legacy `chalaniNo`), which already exists on `Sale`
 * independently. That is a more useful report than reproducing legacy's
 * unfiltered ledger verbatim (which would just be Sales Register with two
 * mostly-empty extra columns) and is what was asked for.
 *
 * Beyond legacy, the report can be narrowed by customer and by a free-text
 * search of the note itself, and exported to Excel.
 */
class SalesWithNoteReportController extends Controller
{
    public function index(Request $request): Response
    {
        $branchFilter = BranchFilter::fromRequest($request);
        $report = $this->report($request, $branchFilter);

        return Inertia::render('Tenant/Reports/SalesWithNote', [
            'sales' => $report['sales'],
            'stores' => BranchStock::storeOptions($branchFilter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'from' => $report['from'],
            'to' => $report['to'],
            'storeId' => $report['storeId'],
            'customerId' => $report['customerId'],
            'search' => $report['search'],
            'totals' => $report['totals'],
            'branchReport' => $branchFilter->payload(),
        ]);
    }

    /**
     * Same rows and totals as index(), streamed as a real .xlsx.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $branchFilter = BranchFilter::fromRequest($request);
        $report = $this->report($request, $branchFilter);

        return Excel::download(
            new SalesWithNoteExport(
                $report['sales'],
                $report['totals'],
                $report['from'],
                $report['to'],
                $branchFilter->isActive(),
                $branchFilter->isActive() ? $branchFilter->label() : null,
                $report['storeId'] !== null ? Store::query()->whereKey($report['storeId'])->value('name') : null,
                $report['customerId'] !== null ? Customer::query()->whereKey($report['customerId'])->value('name') : null,
                $report['search'] !== '' ? $report['search'] : null,
            ),
            "sales-with-note-{$report['from']}-to-{$report['to']}.xlsx",
        );
    }

    /**
     * The one source of the rows and totals, shared by the page and its
     * Excel export so both always agree.
     *
     * @return array{from: string, to: string, storeId: int|null, customerId: int|null, search: string, sales: list<array<string, mixed>>, totals: array{count: int, total: string}}
     */
    private function report(Request $request, BranchFilter $branchFilter): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'regex:/^\d{4}-\d{2}-\d{2}$/'],
            'to' => array_merge(['nullable', 'date_format:Y-m-d', 'regex:/^\d{4}-\d{2}-\d{2}$/'], $request->filled('from') ? ['after_or_equal:from'] : []),
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        [$defaultFrom, $defaultTo] = $this->defaultDateRange();

        $from = $request->string('from')->toString() ?: $defaultFrom;
        $to = $request->string('to')->toString() ?: $defaultTo;

        if ($from > $to) {
            throw ValidationException::withMessages(['to' => 'The to date must be on or after the from date.']);
        }

        $storeId = $branchFilter->allowedStoreId($request->integer('store_id') ?: null);
        $customerId = $request->integer('customer_id') ?: null;
        $search = trim($request->string('search')->toString());

        $sales = Sale::query()
            ->with(['customer:id,name', 'lines.item:id,name,unit', 'lines.itemUnit:id,name'])
            ->where('status', 'posted')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->whereNotNull('narration')
            ->whereRaw("TRIM(narration) <> ''")
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->when($customerId, fn ($query) => $query->where('customer_id', $customerId))
            ->when($search !== '', fn ($query) => $query->where('narration', 'like', '%'.addcslashes($search, '\\%_').'%'))
            ->tap(fn ($query) => $branchFilter->applyToDocuments($query, 'sales.branch_id'))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return [
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
            'customerId' => $customerId,
            'search' => $search,
            'sales' => $sales->map(fn (Sale $sale): array => [
                'id' => $sale->id,
                'date' => $sale->date->toDateString(),
                'invoice_number' => $sale->invoice_number,
                'customer' => $sale->buyer_name ?: $sale->customer?->name,
                'items' => $sale->lines->map(fn ($line): array => [
                    'name' => $line->item?->name,
                    'quantity' => Quantity::of($line->quantity)->toString(),
                    // The unit the line was ENTERED in, not the base unit: a
                    // line sold in Boxes printed "2 Piece" before (audit P1).
                    'unit' => $line->itemUnit?->name ?? $line->item?->unit,
                ])->values()->all(),
                'note' => $sale->narration,
                'chalani_number' => $sale->chalani_number,
                'total' => $sale->total,
                ...$branchFilter->rowBranch($sale->branch_id),
            ])->values()->all(),
            'totals' => [
                'count' => $sales->count(),
                'total' => Money::sum($sales->map(fn (Sale $sale) => Money::of($sale->total)))->toString(),
            ],
        ];
    }

    /**
     * The current open fiscal year's range, falling back to month-to-date if
     * no fiscal year exists yet.
     *
     * @return array{0: string, 1: string}
     */
    private function defaultDateRange(): array
    {
        $fiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

        if ($fiscalYear) {
            return [$fiscalYear->start_date->toDateString(), $fiscalYear->end_date->toDateString()];
        }

        return [now()->startOfMonth()->toDateString(), now()->toDateString()];
    }
}
