<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Exports\Manufacturing\ManufacturingReportExport;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Support\Branches\BranchFilter;
use App\Support\Manufacturing\ManufacturingReports;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Thin HTTP layer over App\Support\Manufacturing\ManufacturingReports (MC10):
 * the stock ledgers, the inventory schedule, the COGM / COGS schedule and the
 * reconciliation view. Each has a page, a PDF and an Excel export; the PDF
 * and the export are built from the same flattened table so the three never
 * disagree. Every figure reaches the page as an exact decimal string.
 *
 * The date range defaults to the current fiscal year, like the Item Ledger.
 * The schedules read GL balances from the fiscal year that contains the
 * range's end date.
 *
 * Branches (plan 12, BR15): every page, PDF and export takes BranchFilter
 * (`branch`, `branches[]`) and passes it to ManufacturingReports, so the
 * Income Statement and Balance Sheet can link here with their own branch
 * params. Pages get `branchReport` (null when not multi-branch); the PDF
 * gets `branchFilterLabel` and the Excel export a label row.
 */
class ManufacturingReportController extends Controller
{
    private const REPORTS = ['stock-ledger', 'inventory-schedule', 'cogs-schedule', 'reconciliation'];

    public function __construct(private readonly ManufacturingReports $reports) {}

    public function stockLedger(Request $request): Response
    {
        [$from, $to] = $this->range($request);
        $filter = BranchFilter::fromRequest($request);

        return Inertia::render('Tenant/Reports/Manufacturing/StockLedger', [
            'ledger' => $this->ledgerFor($request, $from, $to, $filter),
            'class' => $this->ledgerClass($request),
            'itemId' => $this->itemId($request),
            'items' => Item::query()->whereIn('inventory_class', ['raw_material', 'finished_good'])->orderBy('name')->get(['id', 'name', 'inventory_class']),
            'from' => $from,
            'to' => $to,
            'branchReport' => $filter->payload(),
        ]);
    }

    public function inventorySchedule(Request $request): Response
    {
        [, $to] = $this->range($request);
        $fiscalYear = $this->fiscalYear($to);
        $filter = BranchFilter::fromRequest($request);

        return Inertia::render('Tenant/Reports/Manufacturing/InventorySchedule', [
            'schedule' => $this->reports->inventorySchedule($fiscalYear, $to, $filter),
            'asOf' => $to,
            // A Balance Sheet note: for part of the company a management
            // report, like the branch Balance Sheet it explains.
            'branchReport' => $filter->payload(isStatement: true),
        ]);
    }

    public function cogsSchedule(Request $request): Response
    {
        [$from, $to] = $this->range($request);
        $fiscalYear = $this->fiscalYear($to);
        $filter = BranchFilter::fromRequest($request);

        return Inertia::render('Tenant/Reports/Manufacturing/CogsSchedule', [
            'schedule' => $this->reports->cogsSchedule($fiscalYear, $from, $to, $filter),
            'from' => $from,
            'to' => $to,
            'branchReport' => $filter->payload(isStatement: true),
        ]);
    }

    public function reconciliation(Request $request): Response
    {
        [, $to] = $this->range($request);
        $fiscalYear = $this->fiscalYear($to);
        $filter = BranchFilter::fromRequest($request);

        return Inertia::render('Tenant/Reports/Manufacturing/Reconciliation', [
            'reconciliation' => $this->reports->reconciliation($fiscalYear, $to, $filter),
            'asOf' => $to,
            'branchReport' => $filter->payload(),
        ]);
    }

    public function print(Request $request, string $report): HttpResponse
    {
        $filter = BranchFilter::fromRequest($request);
        $document = $this->document($request, $report, $filter);

        $pdf = Pdf::loadView('pdf.manufacturing-report', [
            'title' => $document['title'],
            'company' => CompanySetting::current(),
            'documentNumber' => $document['title'],
            'documentDate' => $document['to'],
            'dateAd' => $document['to'],
            'dateBs' => NepaliCalendar::formatBs($document['to']),
            'from' => $document['from'],
            'to' => $document['to'],
            'headings' => $document['headings'],
            'numericColumns' => $document['numeric'],
            'rows' => $document['rows'],
            // Printed from BR16 on; null when not multi-branch.
            'branchFilterLabel' => $this->branchFilterLabel($filter),
        ]);

        return $pdf->stream("manufacturing-{$report}.pdf");
    }

    public function export(Request $request, string $report): BinaryFileResponse
    {
        $filter = BranchFilter::fromRequest($request);
        $document = $this->document($request, $report, $filter);

        return Excel::download(
            new ManufacturingReportExport($document['headings'], $document['rows'], $document['numeric'], $this->branchFilterLabel($filter)),
            "manufacturing-{$report}.xlsx",
        );
    }

    /**
     * The branch label for the PDF and Excel header, or null for a tenant
     * that is not multi-branch.
     */
    private function branchFilterLabel(BranchFilter $filter): ?string
    {
        return $filter->isActive() ? $filter->label() : null;
    }

    /**
     * The validated [from, to] window, each defaulting to the current fiscal
     * year's bound; an inverted pair falls back to the default.
     *
     * @return array{0: string, 1: string}
     */
    private function range(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        [$defaultFrom, $defaultTo] = $this->reports->defaultRange();

        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->toDateString() : $defaultFrom;
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->toDateString() : $defaultTo;

        return $from > $to ? [$defaultFrom, $defaultTo] : [$from, $to];
    }

    private function fiscalYear(string $date): FiscalYear
    {
        return $this->reports->fiscalYearFor($date)
            ?? abort(422, FiscalYear::NO_OPEN_YEAR_MESSAGE);
    }

    private function ledgerClass(Request $request): string
    {
        $validated = $request->validate([
            'class' => ['nullable', Rule::in(ManufacturingReports::classKeys())],
        ]);

        return $validated['class'] ?? ManufacturingReports::CLASS_RAW_MATERIAL;
    }

    private function itemId(Request $request): ?int
    {
        $validated = $request->validate(['item_id' => ['nullable', 'integer', 'exists:items,id']]);

        return isset($validated['item_id']) ? (int) $validated['item_id'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function ledgerFor(Request $request, string $from, string $to, BranchFilter $filter): array
    {
        $class = $this->ledgerClass($request);

        return $this->reports->stockLedger(
            $class,
            $from,
            $to,
            $class === ManufacturingReports::CLASS_WIP ? null : $this->itemId($request),
            $filter,
        );
    }

    /**
     * One report flattened to headings plus rows of cells, shared by the PDF
     * and the Excel export. Cells are strings or exact Money / Quantity
     * objects (numeric columns); the export converts those to numbers at the
     * cell boundary only.
     *
     * @return array{title: string, from: string, to: string, headings: array<int, string>, numeric: array<int, int>, rows: array<int, array{cells: array<int, mixed>, style: string}>}
     */
    private function document(Request $request, string $report, BranchFilter $filter): array
    {
        abort_unless(in_array($report, self::REPORTS, true), 404);

        [$from, $to] = $this->range($request);

        return match ($report) {
            'stock-ledger' => $this->stockLedgerDocument($request, $from, $to, $filter),
            'inventory-schedule' => $this->scheduleDocument($from, $to, $filter),
            'cogs-schedule' => $this->cogsDocument($from, $to, $filter),
            'reconciliation' => $this->reconciliationDocument($from, $to, $filter),
        };
    }

    /**
     * @return array{title: string, from: string, to: string, headings: array<int, string>, numeric: array<int, int>, rows: array<int, array{cells: array<int, mixed>, style: string}>}
     */
    private function stockLedgerDocument(Request $request, string $from, string $to, BranchFilter $filter): array
    {
        $ledger = $this->ledgerFor($request, $from, $to, $filter);
        $quantity = fn (?string $value) => $value === null ? null : Quantity::of($value);
        $money = fn (?string $value) => $value === null ? null : Money::of($value);

        $rows = [[
            'cells' => [null, 'Opening balance', null, null, $quantity($ledger['opening']['quantity']), null, null, $money($ledger['opening']['value'])],
            'style' => 'subtotal',
        ]];

        foreach ($ledger['rows'] as $row) {
            $rows[] = [
                'cells' => [
                    $row['date'],
                    $row['document'],
                    $quantity($row['inQuantity']),
                    $quantity($row['outQuantity']),
                    $quantity($row['balanceQuantity']),
                    $money($row['inValue']),
                    $money($row['outValue']),
                    $money($row['balanceValue']),
                ],
                'style' => 'line',
            ];
        }

        $rows[] = [
            'cells' => [null, 'Closing balance', null, null, $quantity($ledger['closing']['quantity']), null, null, $money($ledger['closing']['value'])],
            'style' => 'total',
        ];

        return [
            'title' => "{$ledger['label']} Stock Ledger",
            'from' => $from,
            'to' => $to,
            'headings' => ['Date', 'Document', 'In Qty', 'Out Qty', 'Balance Qty', 'In Value', 'Out Value', 'Balance Value'],
            'numeric' => [2, 3, 4, 5, 6, 7],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{title: string, from: string, to: string, headings: array<int, string>, numeric: array<int, int>, rows: array<int, array{cells: array<int, mixed>, style: string}>}
     */
    private function scheduleDocument(string $from, string $to, BranchFilter $filter): array
    {
        $schedule = $this->reports->inventorySchedule($this->fiscalYear($to), $to, $filter);
        $money = fn (?string $value) => $value === null ? null : Money::of($value);

        $rows = array_map(fn (array $row) => [
            'cells' => [$row['label'], $money($row['gl']), $money($row['subledger']), $money($row['difference'])],
            'style' => 'line',
        ], $schedule['rows']);

        $rows[] = ['cells' => ['Total inventory', $money($schedule['total']), null, null], 'style' => 'total'];

        return [
            'title' => 'Inventory Schedule',
            'from' => $from,
            'to' => $to,
            'headings' => ['Inventory', 'Per ledger', 'Per subledger', 'Difference'],
            'numeric' => [1, 2, 3],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{title: string, from: string, to: string, headings: array<int, string>, numeric: array<int, int>, rows: array<int, array{cells: array<int, mixed>, style: string}>}
     */
    private function cogsDocument(string $from, string $to, BranchFilter $filter): array
    {
        $schedule = $this->reports->cogsSchedule($this->fiscalYear($to), $from, $to, $filter);

        $rows = array_map(fn (array $line) => [
            'cells' => [$line['label'], Money::of($line['amount'])],
            'style' => $line['style'],
        ], $schedule['lines']);

        return [
            'title' => 'COGM and COGS Schedule',
            'from' => $from,
            'to' => $to,
            'headings' => ['Particulars', 'Amount'],
            'numeric' => [1],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{title: string, from: string, to: string, headings: array<int, string>, numeric: array<int, int>, rows: array<int, array{cells: array<int, mixed>, style: string}>}
     */
    private function reconciliationDocument(string $from, string $to, BranchFilter $filter): array
    {
        $reconciliation = $this->reports->reconciliation($this->fiscalYear($to), $to, $filter);

        $rows = array_map(fn (array $row) => [
            'cells' => [$row['label'], Money::of($row['gl']), Money::of($row['subledger']), Money::of($row['difference'])],
            'style' => Money::of($row['difference'])->isZero() ? 'line' : 'alert',
        ], $reconciliation['rows']);

        return [
            'title' => 'Inventory Reconciliation',
            'from' => $from,
            'to' => $to,
            'headings' => ['Inventory', 'Per ledger', 'Per subledger', 'Difference'],
            'numeric' => [1, 2, 3],
            'rows' => $rows,
        ];
    }
}
