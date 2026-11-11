<?php

namespace App\Http\Controllers\Tenant\Purchases;

use App\Enums\DepreciationMethod;
use App\Enums\DepreciationPool;
use App\Exports\CapitalPurchaseListExport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CapitalPurchase;
use App\Models\CapitalPurchaseSettlement;
use App\Models\CompanySetting;
use App\Models\JournalVoucher;
use App\Models\PrintLog;
use App\Models\Store;
use App\Models\Supplier;
use App\Support\AmountInWords;
use App\Support\Billing\BillingException;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchContext;
use App\Support\Branches\BranchFormOptions;
use App\Support\Branches\BranchListFilter;
use App\Support\Branches\BranchStoreOptions;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;

class CapitalPurchaseController extends Controller
{
    public function index(Request $request): Response
    {
        // Branches (plan 12, BR12): the list follows the top-bar switcher and
        // names each row's branch only when several branches can appear. A
        // tenant that is not multi-branch gets the same query as before.
        $capitalPurchases = BranchListFilter::apply(CapitalPurchase::query(), $request)
            ->with(['supplier:id,name,tpin', 'lines.account:id,code,name', 'journalVoucher:id,voucher_number,series_branch_id', 'settlements', 'canceller:id,name'])
            ->when(BranchListFilter::columnVisible($request), fn ($query) => $query->with('branch:id,name,code'))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->each(function (CapitalPurchase $capitalPurchase): void {
                $capitalPurchase->setAttribute('outstanding_amount', $capitalPurchase->outstandingAmount()->toString());
            });

        // Per-branch numbering (BR20): the "Voucher #" action prints the
        // series code; no query when every voucher is in the shared series.
        JournalVoucher::loadSeriesBranches($capitalPurchases->pluck('journalVoucher'));

        // The settlement list under each bill is branch-scoped while the
        // outstanding balance is company-wide; a restricted viewer gets the
        // difference as one "paid from other branches" figure, so the list
        // still adds up (plan 12, BR12). Nobody else gets the attribute.
        foreach ($this->settledInOtherBranches($capitalPurchases) as $capitalPurchaseId => $settled) {
            $capitalPurchases->firstWhere('id', $capitalPurchaseId)?->setAttribute('settled_in_other_branches', $settled->toString());
        }

        return Inertia::render('Tenant/Purchases/CapitalPurchases/Index', [
            'capitalPurchases' => $capitalPurchases,
            // Exact SQL sum over every capital purchase (item 8, "totals
            // row") - never a page's worth of client-side addition. Cancelled
            // bills stay listed but never move the total (flags G-02). Narrowed
            // by the same branch filter as the list, so the totals row adds up
            // the rows on screen.
            'totals' => [
                'total' => Money::round(
                    BranchListFilter::apply(CapitalPurchase::query(), $request)->where('status', '!=', 'cancelled')->toBase()->selectRaw('COALESCE(SUM(total), 0) as total')->value('total')
                )->toString(),
            ],
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'tpin']),
            // The inter-branch control accounts are posted only by the system
            // (BranchAccounts), so the pickers never offer them.
            'accounts' => BranchAccounts::exclude(Account::query())->orderBy('name')->get(['id', 'code', 'name']),
            // Mirrors the capital_purchases.cancel route gate so the page
            // hides Cancel instead of offering a 403.
            'canCancel' => (bool) request()->user()?->can('capital_purchases.cancel'),
            'stores' => $this->storeOptions($request),
            'defaultVatRate' => (string) (CompanySetting::current()->default_vat_rate ?? '13.00'),
            // Asset register (item 5): only accounts filed under Fixed
            // Assets can create a FixedAsset from their line.
            'depreciationCategories' => array_map(fn (DepreciationPool $pool) => $pool->value, DepreciationPool::cases()),
            'depreciationMethods' => array_map(fn (DepreciationMethod $method) => $method->value, DepreciationMethod::cases()),
            // Branch picker of the settlement form; the form starts on the
            // bill's own branch when it is one of these (no clearing lines).
            // The create form's picker uses the same two props.
            ...BranchFormOptions::props($request),
        ]);
    }

    /**
     * Per bill, the posted settlements a branch-restricted viewer cannot see
     * (paid from a branch outside their access): the company-wide total
     * minus the visible rows. Empty for an unrestricted viewer, who sees
     * every settlement. The company-wide amounts are a READ inside
     * BranchContext::unrestricted() and are only ever shown as one sum, never
     * as rows.
     *
     * @param  iterable<CapitalPurchase>  $capitalPurchases  With `settlements` loaded through the viewer's scope.
     * @return array<int, Money> Only bills with a positive hidden amount.
     */
    private function settledInOtherBranches(iterable $capitalPurchases): array
    {
        if (BranchContext::allowedBranchIds() === null) {
            return [];
        }

        $visibleById = [];

        foreach ($capitalPurchases as $capitalPurchase) {
            $visibleById[$capitalPurchase->id] = Money::sum($capitalPurchase->settlements
                ->where('status', 'posted')
                ->map(fn (CapitalPurchaseSettlement $settlement): Money => Money::of($settlement->amount)));
        }

        if ($visibleById === []) {
            return [];
        }

        $companyWide = BranchContext::unrestricted(fn () => CapitalPurchaseSettlement::query()
            ->whereIn('capital_purchase_id', array_keys($visibleById))
            ->where('status', 'posted')
            ->get(['capital_purchase_id', 'amount']))
            ->groupBy('capital_purchase_id');

        $hidden = [];

        foreach ($visibleById as $capitalPurchaseId => $visible) {
            $total = Money::sum(($companyWide->get($capitalPurchaseId) ?? collect())
                ->map(fn (CapitalPurchaseSettlement $settlement): Money => Money::of($settlement->amount)));
            $other = $total->minus($visible);

            if ($other->isPositive()) {
                $hidden[$capitalPurchaseId] = $other;
            }
        }

        return $hidden;
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
            'bank_account_id' => ['nullable', 'exists:accounts,id', BranchAccounts::notControlAccountRule()],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            // Optional (plan 12): CapitalPurchase::post() falls back to the
            // store's branch, then the user's posting branch, and refuses a
            // store of another branch.
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'cash_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'bank_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            // The company rate or 0, nothing typed in between (flags G-03);
            // the model re-checks inside post().
            'vat_rate' => ['nullable', 'numeric', 'decimal:0,2', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    CapitalPurchase::assertAllowedVatRate($value);
                } catch (InvalidArgumentException $e) {
                    $fail($e->getMessage());
                }
            }],
            'expected_total' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'exists:accounts,id', BranchAccounts::notControlAccountRule()],
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
            // The fiscal-year guard stays on `date`; a branch access refusal
            // raised this way goes to the branch picker (plan 12, BR12).
            return back()->withErrors([BranchFormOptions::errorField($e, 'date') => $e->getMessage()])->withInput();
        } catch (InvalidArgumentException $e) {
            // Branch refusals to the branch picker, a store outside the bill's
            // branch to the store field, the rest under `lines` as before.
            return back()->withErrors([BranchFormOptions::errorField($e, 'lines', storeField: 'store_id') => $e->getMessage()])->withInput();
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
     * everything, same as the page shows. That includes the top-bar branch
     * switcher (plan 12, BR12): narrowed to one branch, the page and the file
     * both hold only that branch's bills.
     */
    public function export(Request $request)
    {
        $capitalPurchases = BranchListFilter::apply(CapitalPurchase::query(), $request)
            ->with(['supplier:id,name', 'canceller:id,name'])
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
            'cancelled_on' => $capitalPurchase->cancelled_at?->toDateString(),
            'cancelled_by' => $capitalPurchase->canceller?->name,
            'cancel_reason' => $capitalPurchase->cancel_reason,
        ]);

        $total = Money::sum($capitalPurchases
            ->reject(fn (CapitalPurchase $capitalPurchase): bool => $capitalPurchase->status === 'cancelled')
            ->map(fn (CapitalPurchase $capitalPurchase): Money => Money::of($capitalPurchase->total)))->toString();

        return Excel::download(new CapitalPurchaseListExport($rows, $total), 'capital-purchases.xlsx');
    }

    /**
     * Printable capital or service bill (flags G-01): lines, VAT split,
     * payment, the payments made against it and what is still owed. Every
     * print is logged, so a reprint says "Copy of Original" (CONTRACTS C9).
     * A cancelled bill still prints, marked with who cancelled it, when and
     * why.
     */
    public function print(Request $request, CapitalPurchase $capitalPurchase): HttpResponse
    {
        $capitalPurchase->load([
            'supplier', 'bankAccount', 'lines.account', 'journalVoucher.fiscalYear', 'canceller:id,name',
            'settlements' => fn ($query) => $query->with('bankAccount')->orderBy('date')->orderBy('id'),
        ]);

        $documentDate = $capitalPurchase->date->toDateString();
        $total = Money::of($capitalPurchase->total);
        $voucherNumber = $capitalPurchase->journalVoucher?->seriesNumber();
        $settledInOtherBranches = $this->settledInOtherBranches([$capitalPurchase])[$capitalPurchase->id] ?? null;

        return Pdf::loadView('pdf.capital-purchase', [
            'capitalPurchase' => $capitalPurchase,
            'company' => CompanySetting::current(),
            'documentNumber' => $capitalPurchase->bill_number ?? ($voucherNumber !== null ? "Voucher #{$voucherNumber}" : "#{$capitalPurchase->id}"),
            'documentDate' => $documentDate,
            'paymentModeLabel' => match ($capitalPurchase->payment_mode) {
                'partial' => 'Cash + bank',
                default => ucfirst($capitalPurchase->payment_mode),
            },
            'taxable' => Money::of($capitalPurchase->taxable_amount),
            'nontaxable' => Money::of($capitalPurchase->nontaxable_amount),
            'vat' => Money::of($capitalPurchase->vat_amount),
            'total' => $total,
            'outstanding' => $capitalPurchase->outstandingAmount(),
            'settlements' => $capitalPurchase->settlements,
            // Restricted viewer only: settlements paid from branches they
            // cannot see, as one line, so the list matches the outstanding.
            'settledInOtherBranches' => $settledInOtherBranches,
            'settledInOtherBranchesLabel' => $settledInOtherBranches === null
                ? null
                : 'Paid from other '.Str::lower(Str::plural(ActiveBranchSelection::label())),
            'copyNumber' => PrintLog::record($capitalPurchase, $request->user()),
            'dateAd' => $documentDate,
            'dateBs' => NepaliCalendar::formatBs($documentDate),
            'fiscalYearName' => $capitalPurchase->journalVoucher?->fiscalYear?->name,
            'amountInWords' => AmountInWords::rupees($total),
            ...$this->branchHeader($request, $capitalPurchase->branch_id),
        ])->stream("capital-purchase-{$capitalPurchase->id}.pdf");
    }

    /**
     * The branch line of a printed bill's header (plan 12, BR12), only for a
     * multi-branch company; everyone else gets nulls and the header prints
     * exactly as before.
     *
     * @return array{branchName: ?string, branchLabel: ?string}
     */
    private function branchHeader(Request $request, ?int $branchId): array
    {
        if ($branchId === null || ! ActiveBranchSelection::isMultiBranch($request)) {
            return ['branchName' => null, 'branchLabel' => null];
        }

        return [
            'branchName' => Branch::query()->whereKey($branchId)->value('name'),
            'branchLabel' => ActiveBranchSelection::label(),
        ];
    }

    /**
     * Active stores for the create form. A multi-branch company also gets
     * each store's branch_id, so the form offers only the chosen branch's
     * stores (plan 12, section 5.5), and a branch-restricted user only sees
     * stores of branches they may use. Anyone else gets the same list as
     * before.
     *
     * @return Collection<int, Store>
     */
    private function storeOptions(Request $request): Collection
    {
        // BranchStoreOptions (BR22 C5) also skips stores of a deactivated
        // branch, which nothing can post into; untouched when not
        // multi-branch, and the same columns either way.
        return BranchStoreOptions::apply(Store::where('is_active', true), $request)
            ->orderBy('name')
            ->get(BranchStoreOptions::columns($request, ['id', 'name']));
    }

    public function cancel(Request $request, CapitalPurchase $capitalPurchase): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $capitalPurchase->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException|AuthorizationException $e) {
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

        // Company-wide, like the unique index: a bill filed in a branch the
        // user cannot see still counts.
        if (BranchContext::unrestricted(fn () => CapitalPurchase::query()->where('bill_number_guard', $guard)->exists())) {
            throw ValidationException::withMessages([
                'bill_number' => 'This bill number has already been entered for this supplier.',
            ]);
        }
    }
}
