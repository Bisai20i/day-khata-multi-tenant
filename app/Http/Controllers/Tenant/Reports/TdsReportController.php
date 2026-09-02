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
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TDS (Tax Deducted at Source) compliance report over a date range: every
 * posted Sale withholding TDS (a claimable credit) and every posted
 * Purchase withholding TDS (a liability owed to the tax authority), each
 * reported net of the proportional TDS share reversed by any non-cancelled
 * return against it - see SalesReturn::post()/PurchaseReturn::post()'s own
 * docblocks for the `tdsShare = round(original.tds_amount * (return.total /
 * original.total), 2)` formula this mirrors. A partially-returned invoice's
 * raw `tds_amount` column overstates what is actually still withheld, so
 * this report never reads that column directly for display - only the
 * computed net figure.
 */
class TdsReportController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;

        $sales = Sale::query()
            ->with(['customer:id,name', 'journalVoucher:id,voucher_number', 'tdsAccount:id,name', 'returns'])
            ->where('status', 'posted')
            ->whereNotNull('tds_account_id')
            ->whereBetween('date', [$from, $to])
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $salesRows = $sales
            ->map(fn (Sale $sale) => $this->saleRow($sale))
            ->filter()
            ->values();

        $purchases = Purchase::query()
            ->with(['supplier:id,name', 'journalVoucher:id,voucher_number', 'tdsAccount:id,name', 'returns'])
            ->where('status', 'posted')
            ->whereNotNull('tds_account_id')
            ->whereBetween('date', [$from, $to])
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $purchaseRows = $purchases
            ->map(fn (Purchase $purchase) => $this->purchaseRow($purchase))
            ->filter()
            ->values();

        $salesTotal = round((float) $salesRows->sum('net_tds_amount'), 2);
        $purchasesTotal = round((float) $purchaseRows->sum('net_tds_amount'), 2);

        return Inertia::render('Tenant/Reports/TdsReport', [
            'sales' => $salesRows,
            'purchases' => $purchaseRows,
            'salesTotal' => $salesTotal,
            'purchasesTotal' => $purchasesTotal,
            'grandTotal' => round($salesTotal + $purchasesTotal, 2),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
        ]);
    }

    /**
     * @return array{id: int, date: string, voucher_number: string|null, party: string|null, total: float, net_tds_amount: float, tds_account: string|null}|null
     */
    private function saleRow(Sale $sale): ?array
    {
        $netTdsAmount = $this->netTdsAmount(
            (float) $sale->tds_amount,
            (float) $sale->total,
            $sale->returns->map(fn (SalesReturn $return) => ['status' => $return->status, 'total' => (float) $return->total]),
        );

        if ($netTdsAmount <= 0.01) {
            return null;
        }

        return [
            'id' => $sale->id,
            'date' => $sale->date->toDateString(),
            'voucher_number' => $sale->journalVoucher?->voucher_number,
            'party' => $sale->customer?->name,
            'total' => round((float) $sale->total, 2),
            'net_tds_amount' => $netTdsAmount,
            'tds_account' => $sale->tdsAccount?->name,
        ];
    }

    /**
     * @return array{id: int, date: string, voucher_number: string|null, party: string|null, total: float, net_tds_amount: float, tds_account: string|null}|null
     */
    private function purchaseRow(Purchase $purchase): ?array
    {
        $netTdsAmount = $this->netTdsAmount(
            (float) $purchase->tds_amount,
            (float) $purchase->total,
            $purchase->returns->map(fn (PurchaseReturn $return) => ['status' => $return->status, 'total' => (float) $return->total]),
        );

        if ($netTdsAmount <= 0.01) {
            return null;
        }

        return [
            'id' => $purchase->id,
            'date' => $purchase->date->toDateString(),
            'voucher_number' => $purchase->journalVoucher?->voucher_number,
            'party' => $purchase->supplier?->name,
            'total' => round((float) $purchase->total, 2),
            'net_tds_amount' => $netTdsAmount,
            'tds_account' => $purchase->tdsAccount?->name,
        ];
    }

    /**
     * Net TDS still withheld on a transaction: its own `tds_amount` less the
     * proportional share reversed by every non-cancelled return against it,
     * `tdsShare = round(tds_amount * (return.total / total), 2)` - the exact
     * formula SalesReturn::post()/PurchaseReturn::post() use when reversing
     * TDS on a partial return.
     *
     * @param  Collection<int, array{status: string, total: float}>  $returns
     */
    private function netTdsAmount(float $tdsAmount, float $total, Collection $returns): float
    {
        if ($tdsAmount <= 0 || $total <= 0) {
            return 0.0;
        }

        $reversedShare = $returns
            ->reject(fn (array $return) => $return['status'] === 'cancelled')
            ->reduce(fn (float $carry, array $return) => round($carry + round($tdsAmount * ($return['total'] / $total), 2), 2), 0.0);

        return round($tdsAmount - $reversedShare, 2);
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
