<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Exports\UnifiedVatRegisterExport;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Store;
use App\Support\Branches\BranchFilter;
use App\Support\Branches\BranchStock;
use App\Support\Reports\UnifiedVatRegister;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

/**
 * The Unified VAT Register: sales, sales returns, purchases and purchase
 * returns of a filing period in one chronological list (the legacy
 * "vat-report/all" page), with output and input VAT totalled separately and
 * net VAT payable beneath them.
 *
 * Every row comes from the four VAT books' own payload builders
 * (SalesPurchaseReportController), never from a parallel query, so this page,
 * the books and the VAT Summary always agree. See UnifiedVatRegister for how
 * the rows are re-signed and totalled.
 *
 * Branches (plan 12, BR14): the same BranchFilter is handed to the four
 * payloads; in a multi-branch tenant each row names its branch.
 */
class UnifiedVatRegisterController extends Controller
{
    public function index(Request $request): Response
    {
        $branchFilter = BranchFilter::fromRequest($request);
        $register = $this->register($request, $branchFilter);

        return Inertia::render('Tenant/Reports/UnifiedVatRegister', [
            ...$register,
            'stores' => BranchStock::storeOptions($branchFilter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'branchReport' => $branchFilter->payload(),
        ]);
    }

    /**
     * Same rows and totals as index(), streamed as a real .xlsx.
     */
    public function export(Request $request)
    {
        $branchFilter = BranchFilter::fromRequest($request);
        $register = $this->register($request, $branchFilter);

        return Excel::download(
            new UnifiedVatRegisterExport(
                $register['rows'],
                $register['totals'],
                $register['from'],
                $register['to'],
                $branchFilter->isActive(),
                $branchFilter->isActive() ? $branchFilter->label() : null,
            ),
            "unified-vat-register-{$register['from']}-to-{$register['to']}.xlsx",
        );
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>, from: string, to: string, storeId: int|null}
     */
    private function register(Request $request, BranchFilter $branchFilter): array
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;

        $books = new SalesPurchaseReportController;

        return [
            ...UnifiedVatRegister::build(
                $books->salesVatBookPayload($from, $to, $storeId, $branchFilter)['rows'],
                $books->salesReturnRegisterPayload($from, $to, $storeId, $branchFilter)['rows'],
                $books->purchaseVatBookPayload($from, $to, $storeId, $branchFilter)['rows'],
                $books->purchaseReturnRegisterPayload($from, $to, $storeId, $branchFilter)['rows'],
            ),
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
        ];
    }

    /**
     * Defaults to the current open fiscal year when no explicit range is
     * given, falling back to month-to-date if no fiscal year exists yet.
     * A lone `from` or `to` is rejected (422), never silently widened to
     * the fiscal year.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(Request $request): array
    {
        // Strict Y-m-d, as every other report: a malformed, reversed or
        // half-supplied range, or an unknown store, is a validation error the
        // page shows, and the download name can only ever contain a clean date.
        $request->validate([
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
        ]);

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
