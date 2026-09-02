<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Store;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Net VAT payable/refundable for a filing period - the figure a Nepali VAT
 * return actually asks for, which neither the Sales VAT Book nor the
 * Purchase VAT Book computes on its own since they don't net out VAT
 * reversed by returns.
 *
 * Output VAT = posted Sale.vat_amount in range, minus non-cancelled
 * SalesReturn.vat_amount whose OWN date falls in range (a return posted in
 * period B reduces period B's liability, regardless of which period the
 * original sale fell in - matching how a real VAT return filing works).
 * Input VAT mirrors this via Purchase/PurchaseReturn. Net VAT Payable =
 * Output VAT - Input VAT; positive is owed to the tax authority, negative
 * is a refundable/carry-forward credit.
 */
class VatSummaryReportController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;

        $outputVatGross = round((float) Sale::query()
            ->where('status', 'posted')
            ->whereBetween('date', [$from, $to])
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->sum('vat_amount'), 2);

        $outputVatReturns = round((float) SalesReturn::query()
            ->where('status', '!=', 'cancelled')
            ->whereBetween('date', [$from, $to])
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->sum('vat_amount'), 2);

        $inputVatGross = round((float) Purchase::query()
            ->where('status', 'posted')
            ->whereBetween('date', [$from, $to])
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->sum('vat_amount'), 2);

        $inputVatReturns = round((float) PurchaseReturn::query()
            ->where('status', '!=', 'cancelled')
            ->whereBetween('date', [$from, $to])
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->sum('vat_amount'), 2);

        $outputVatNet = round($outputVatGross - $outputVatReturns, 2);
        $inputVatNet = round($inputVatGross - $inputVatReturns, 2);
        $netVatPayable = round($outputVatNet - $inputVatNet, 2);

        return Inertia::render('Tenant/Reports/VatSummary', [
            'outputVat' => [
                'gross' => $outputVatGross,
                'returns' => $outputVatReturns,
                'net' => $outputVatNet,
            ],
            'inputVat' => [
                'gross' => $inputVatGross,
                'returns' => $inputVatReturns,
                'net' => $inputVatNet,
            ],
            'netVatPayable' => $netVatPayable,
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
