<?php

namespace App\Http\Controllers\Tenant\Purchases;

use App\Enums\DepreciationMethod;
use App\Enums\DepreciationPool;
use App\Exports\CapitalPurchaseListExport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CapitalPurchase;
use App\Models\CompanySetting;
use App\Models\Store;
use App\Models\Supplier;
use App\Support\Billing\BillingException;
use App\Support\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;

class CapitalPurchaseController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Purchases/CapitalPurchases/Index', [
            'capitalPurchases' => CapitalPurchase::query()
                ->with(['supplier:id,name,tpin', 'lines.account:id,code,name', 'journalVoucher:id,voucher_number', 'settlements'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get()
                ->each(function (CapitalPurchase $capitalPurchase): void {
                    $capitalPurchase->setAttribute('outstanding_amount', $capitalPurchase->outstandingAmount()->toString());
                }),
            // Exact SQL sum over every capital purchase (item 8, "totals
            // row") - never a page's worth of client-side addition.
            'totals' => [
                'total' => Money::round(
                    CapitalPurchase::query()->toBase()->selectRaw('COALESCE(SUM(total), 0) as total')->value('total')
                )->toString(),
            ],
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'tpin']),
            'accounts' => Account::query()->orderBy('name')->get(['id', 'code', 'name']),
            'canCancel' => request()->user()?->role?->slug === 'admin',
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'defaultVatRate' => (string) (CompanySetting::current()->default_vat_rate ?? '13.00'),
            // Asset register (item 5): only accounts filed under Fixed
            // Assets can create a FixedAsset from their line.
            'depreciationCategories' => array_map(fn (DepreciationPool $pool) => $pool->value, DepreciationPool::cases()),
            'depreciationMethods' => array_map(fn (DepreciationMethod $method) => $method->value, DepreciationMethod::cases()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'supplier_pan' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:capital,service'],
            'bill_number' => ['nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'narration' => ['nullable', 'string', 'max:255'],
            'payment_mode' => ['required', 'in:cash,bank,partial,credit'],
            'bank_account_id' => ['nullable', 'exists:accounts,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'cash_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'bank_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'expected_total' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'exists:accounts,id'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'lines.*.vatable' => ['nullable', 'boolean'],
            'lines.*.narration' => ['nullable', 'string', 'max:255'],
            // Asset register (item 5): optionally turns a "capital" line
            // into a tracked, depreciating FixedAsset. CapitalPurchase::
            // createAssetForLine() re-checks the account is actually filed
            // under Fixed Assets - this only catches obviously wrong enum
            // values before the transaction.
            'lines.*.create_asset' => ['nullable', 'boolean'],
            'lines.*.asset_name' => ['nullable', 'string', 'max:255'],
            'lines.*.depreciation_category' => ['nullable', Rule::in(array_map(fn (DepreciationPool $pool) => $pool->value, DepreciationPool::cases()))],
            'lines.*.depreciation_method' => ['nullable', Rule::in(array_map(fn (DepreciationMethod $method) => $method->value, DepreciationMethod::cases()))],
            'lines.*.depreciation_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'lines.*.salvage_value' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        // The model holds the authoritative rule (and the unique index behind
        // it) because only it can check inside the posting transaction; this
        // rule catches the ordinary case with a field-level message before the
        // form is even submitted twice.
        $this->assertBillNumberIsNew($data);

        try {
            $capitalPurchase = CapitalPurchase::post($data, $data['lines'], $request->user());
        } catch (BillingException $e) {
            // C8: the browser previewed a total with money.js and sent it back.
            // A difference means the bill on screen was not the bill being
            // saved, so the save is refused rather than booking another amount.
            throw ValidationException::withMessages(
                $e->reason === BillingException::REASON_TOTAL_MISMATCH
                    ? ['expected_total' => 'The bill total changed. Please review it before saving.']
                    : ['lines' => $e->getMessage()]
            );
        } catch (AuthorizationException $e) {
            return back()->withErrors(['date' => $e->getMessage()])->withInput();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.capital-purchases.index')
            ->with('status', 'Capital purchase posted.')
            ->with('created', [
                'type' => 'capital-purchase',
                'id' => $capitalPurchase->id,
            ]);
    }

    /**
     * Excel export of the full capital purchase list (item 8) - the Index
     * page has no server-side filters of its own, so this exports
     * everything, same as the page shows.
     */
    public function export()
    {
        $capitalPurchases = CapitalPurchase::query()
            ->with(['supplier:id,name'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $rows = $capitalPurchases->values()->map(fn (CapitalPurchase $capitalPurchase, int $index): array => [
            'sn' => $index + 1,
            'date' => $capitalPurchase->date->toDateString(),
            'bill_number' => $capitalPurchase->bill_number,
            'type' => ucfirst($capitalPurchase->type),
            'supplier' => $capitalPurchase->supplier?->name,
            'payment_mode' => ucfirst($capitalPurchase->payment_mode),
            'total' => $capitalPurchase->total,
            'status' => $capitalPurchase->status === 'cancelled' ? 'Cancelled' : 'Posted',
        ]);

        $total = Money::sum($capitalPurchases->map(fn (CapitalPurchase $capitalPurchase): Money => Money::of($capitalPurchase->total)))->toString();

        return Excel::download(new CapitalPurchaseListExport($rows, $total), 'capital-purchases.xlsx');
    }

    public function cancel(Request $request, CapitalPurchase $capitalPurchase): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $capitalPurchase->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.capital-purchases.index')->with('status', 'Capital purchase cancelled.');
    }

    /**
     * @param  array{supplier_id?: int|null, bill_number?: string|null}  $data
     */
    private function assertBillNumberIsNew(array $data): void
    {
        $guard = CapitalPurchase::billNumberGuard(
            isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            $data['bill_number'] ?? null,
        );

        if ($guard === null) {
            return;
        }

        if (CapitalPurchase::query()->where('bill_number_guard', $guard)->exists()) {
            throw ValidationException::withMessages([
                'bill_number' => 'This bill number has already been entered for this supplier.',
            ]);
        }
    }
}
