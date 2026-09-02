<?php

namespace App\Http\Controllers\Tenant\Assets;

use App\Enums\DepreciationPool;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

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
            'cost' => ['required', 'numeric', 'min:0.01'],
            'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'depreciation_method' => ['required', 'in:slm,wdv'],
            'depreciation_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'payment_mode' => ['required', 'in:cash,bank,credit'],
            'bank_account_id' => ['nullable', 'exists:accounts,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'narration' => ['nullable', 'string', 'max:255'],
        ], [
            'category.in' => 'Please choose a depreciation pool (Pool A to Pool E).',
        ]);

        try {
            FixedAsset::post($data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['cost' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.fixed-assets.index')->with('status', 'Fixed asset added.');
    }

    public function dispose(Request $request, FixedAsset $fixedAsset): RedirectResponse
    {
        $data = $request->validate([
            'disposal_date' => ['required', 'date'],
            'disposal_amount' => ['nullable', 'numeric', 'min:0'],
            'disposal_mode' => ['required', 'in:cash,bank'],
            'bank_account_id' => ['nullable', 'exists:accounts,id'],
        ]);

        try {
            $fixedAsset->dispose(
                $request->user(),
                $data['disposal_date'],
                (float) ($data['disposal_amount'] ?? 0),
                $data['disposal_mode'],
                $data['bank_account_id'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['disposal_amount' => $e->getMessage()]);
        }

        return redirect()->route('tenant.fixed-assets.index')->with('status', 'Fixed asset disposed.');
    }

    public function postDepreciation(Request $request): RedirectResponse
    {
        $result = FixedAsset::postDepreciationForFiscalYear(FiscalYear::current(), $request->user());

        $message = $result['posted'] > 0
            ? "Depreciation posted for {$result['posted']} asset(s), totaling ".number_format($result['total'], 2).'.'
            : 'No depreciation was due - all assets are already posted for this fiscal year or fully depreciated.';

        return redirect()->route('tenant.fixed-assets.index')->with('status', $message);
    }
}
