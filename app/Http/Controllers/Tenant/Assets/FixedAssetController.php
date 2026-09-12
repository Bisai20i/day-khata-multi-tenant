<?php

namespace App\Http\Controllers\Tenant\Assets;

use App\Enums\DepreciationPool;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\Supplier;
use App\Support\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Every amount in and out of this controller is a decimal STRING, validated
 * with `decimal:0,2` so a rate or a cost can never arrive with more decimals
 * than the column stores, and handed to FixedAsset as-is - no float ever
 * touches an asset cost (CONTRACTS C1/C2).
 *
 * Both exception types the fiscal-year guard can raise are caught on every
 * posting action: ClosedFiscalYearGuard::assertDateInOpenYear() throws
 * InvalidArgumentException for a date in no year or a closed one, and
 * AuthorizationException for a non-admin aiming at a reopened year.
 */
class FixedAssetController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Assets/FixedAssets/Index', [
            'fixedAssets' => FixedAsset::query()
                ->with(['account:id,code,name', 'journalVoucher:id,voucher_number'])
                ->orderByDesc('purchase_date')
                ->orderByDesc('id')
                ->get(),
            // No is_bank flag exists anywhere on Account (see mem.md's Bank
            // Book report finding) - same plain full-account-list picker
            // Purchases/Sales already use for their own bank_account_id field.
            'accounts' => Account::query()->orderBy('name')->get(['id', 'code', 'name']),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'pools' => collect(DepreciationPool::cases())->map(fn (DepreciationPool $pool) => [
                'value' => $pool->value,
                'label' => $pool->value,
                'defaultRate' => $pool->defaultRate(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'asset_name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:'.implode(',', array_column(DepreciationPool::cases(), 'value'))],
            'purchase_date' => ['required', 'date'],
            'cost' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999999'],
            'salvage_value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'depreciation_method' => ['required', 'in:slm,wdv'],
            'depreciation_rate' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'payment_mode' => ['required', 'in:cash,bank,credit'],
            'bank_account_id' => ['nullable', 'required_if:payment_mode,bank', 'exists:accounts,id'],
            'supplier_id' => ['nullable', 'required_if:payment_mode,credit', 'exists:suppliers,id'],
            'narration' => ['nullable', 'string', 'max:255'],
        ], [
            'category.in' => 'Please choose a depreciation pool (Pool A to Pool E).',
            'cost.decimal' => 'The cost may have at most 2 decimal places.',
            'salvage_value.decimal' => 'The salvage value may have at most 2 decimal places.',
            'depreciation_rate.decimal' => 'The depreciation rate may have at most 2 decimal places.',
        ]);

        try {
            FixedAsset::post($data, $request->user());
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['cost' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.fixed-assets.index')->with('status', 'Fixed asset added.');
    }

    public function dispose(Request $request, FixedAsset $fixedAsset): RedirectResponse
    {
        $data = $request->validate([
            'disposal_date' => ['required', 'date'],
            'disposal_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'disposal_mode' => ['required', 'in:cash,bank'],
            'bank_account_id' => ['nullable', 'required_if:disposal_mode,bank', 'exists:accounts,id'],
        ], [
            'disposal_amount.decimal' => 'The disposal amount may have at most 2 decimal places.',
        ]);

        try {
            $fixedAsset->dispose(
                $request->user(),
                $data['disposal_date'],
                $data['disposal_amount'] ?? null,
                $data['disposal_mode'],
                $data['bank_account_id'] ?? null,
            );
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['disposal_amount' => $e->getMessage()]);
        }

        return redirect()->route('tenant.fixed-assets.index')->with('status', 'Fixed asset disposed.');
    }

    public function postDepreciation(Request $request): RedirectResponse
    {
        try {
            $result = FixedAsset::postDepreciationForFiscalYear(FiscalYear::current(), $request->user());
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['depreciation' => $e->getMessage()]);
        }

        $message = $result['posted'] > 0
            ? "Depreciation posted for {$result['posted']} asset(s), totaling ".Money::of($result['total'])->format().'.'
            : 'No depreciation was due - all assets are already posted for this fiscal year or fully depreciated.';

        return redirect()->route('tenant.fixed-assets.index')->with('status', $message);
    }
}
