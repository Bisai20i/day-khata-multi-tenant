<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Item;
use App\Models\PrintLog;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchContext;
use App\Support\Branches\BranchFormOptions;
use App\Support\Branches\BranchListFilter;
use App\Support\Branches\BranchStoreOptions;
use App\Support\Branches\StockInTransit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class StockTransferController extends Controller
{
    /**
     * Branches (plan 12, BR12). A transfer has no branch of its own: it
     * records the branch of each end (from_branch_id, to_branch_id), and
     * StockTransfer::post() takes the branch from the source store. So the
     * form has no branch picker; each store is offered as "Store (Branch)"
     * in a multi-branch company, limited to the stores of the branches the
     * user may use. The list follows the top-bar switcher like the
     * transfer's access scope does: a transfer shows when either end is in
     * the chosen branch. Not multi-branch: same queries and payload as before.
     *
     * Two-step transfers (BR18): `stores` (the source list) stays limited to
     * the branches the user may use, while `destinationStores` offers every
     * active store of every active branch, including branches the user
     * cannot use: sending goods there only needs the source branch, receiving
     * them needs the destination branch. Null when not multi-branch, and the
     * form then uses `stores` for both ends as before. A multi-branch list
     * always carries each store's branch, so a transfer between branches
     * names both branches even when the list is narrowed to one.
     */
    public function index(Request $request): Response
    {
        $narrowedBranchId = ActiveBranchSelection::narrowedBranchId($request);
        $multiBranch = ActiveBranchSelection::isMultiBranch($request);
        $statusFilter = $this->statusFilter($request, $multiBranch);

        return Inertia::render('Tenant/Inventory/StockTransfers/Index', [
            'stockTransfers' => StockTransfer::query()
                ->with(['lines.item:id,name,unit', 'fromStore:id,name', 'toStore:id,name'])
                ->when($narrowedBranchId, fn ($query, int $branchId) => $query->where(fn ($ends) => $ends
                    ->where('from_branch_id', $branchId)
                    ->orWhere('to_branch_id', $branchId)))
                ->when($statusFilter, fn ($query, string $status) => $query->where('status', $status))
                ->when($multiBranch || BranchListFilter::columnVisible($request), fn ($query) => $query->with([
                    'fromStore:id,name,branch_id', 'fromStore.branch:id,name,code',
                    'toStore:id,name,branch_id', 'toStore.branch:id,name,code',
                ]))
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'items' => Item::query()->where('is_stockable', true)->orderBy('name')->get(['id', 'name', 'unit', 'inventory_class']),
            'stores' => BranchStoreOptions::apply(Store::where('is_active', true), $request, withBranch: true)->orderBy('name')->get(),
            'destinationStores' => $multiBranch
                ? Store::query()
                    ->where('is_active', true)
                    ->whereHas('branch', fn ($branch) => $branch->where('is_active', true))
                    ->with('branch:id,name,code')
                    ->orderBy('name')
                    ->get()
                : null,
            // BR19, multi-branch only (absent otherwise, so a Main-only page
            // gets exactly the props it always did).
            ...($multiBranch ? [
                'statusFilter' => $statusFilter,
                'inTransit' => $this->inTransitSummary($narrowedBranchId),
            ] : []),
        ]);
    }

    /**
     * The `status` query filter (BR19, the inter-branch balances report links
     * here with status=in_transit or received). Only for a multi-branch
     * company; an unknown value is ignored.
     */
    private function statusFilter(Request $request, bool $multiBranch): ?string
    {
        $status = $request->string('status')->toString();

        return $multiBranch && in_array($status, [StockTransfer::STATUS_IN_TRANSIT, StockTransfer::STATUS_RECEIVED], true)
            ? $status
            : null;
    }

    /**
     * Transfers between branches in transit right now, by direction relative
     * to the switcher's branch (else a restricted user's branches, else the
     * whole company), for the list's banner; null when there are none.
     * Through the access scope, so a restricted user only counts transfers
     * with an end in their branches.
     *
     * @return array<string, array{count: int, value: string}>|null
     */
    private function inTransitSummary(?int $narrowedBranchId): ?array
    {
        $branchIds = $narrowedBranchId !== null ? [$narrowedBranchId] : BranchContext::allowedBranchIds();
        $summary = StockInTransit::summary($branchIds);

        if ($summary === []) {
            return null;
        }

        return array_map(fn (array $bucket): array => [
            'count' => $bucket['count'],
            'value' => $bucket['value']->toString(),
        ], $summary);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'from_store_id' => ['required', 'integer', 'exists:stores,id'],
            'to_store_id' => ['required', 'integer', 'exists:stores,id', 'different:from_store_id'],
            // Optional (plan 12, BR08): StockTransfer::post() takes the branch
            // from the source store and refuses a mismatch. A destination in
            // another branch makes it a two-step transfer (BR18); the model
            // checks that store is active and in an active branch.
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001', 'decimal:0,4'],
            'lines.*.unit_cost_rate' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'lines.*.remarks' => ['nullable', 'string', 'max:255'],
        ], [
            'to_store_id.different' => 'The source and destination store must be different.',
        ]);

        try {
            $transfer = StockTransfer::post(
                Arr::except($data, ['lines']),
                $data['lines'],
                $request->user(),
            );
        } catch (InvalidArgumentException|AuthorizationException $e) {
            // AuthorizationException as well as InvalidArgumentException:
            // StockTransfer::post() now runs the date through
            // ClosedFiscalYearGuard (CONTRACTS C4), which throws the former
            // when a non-admin aims at a reopened closed year. Letting it
            // escape would render a 403 page instead of a field error.
            return back()->withErrors([$this->errorField($e) => $e->getMessage()])->withInput();
        }

        $message = $transfer->status === StockTransfer::STATUS_IN_TRANSIT
            ? 'Stock transfer dispatched. It stays in transit until the receiving '.strtolower(ActiveBranchSelection::label()).' receives it.'
            : 'Stock transfer posted.';

        return redirect()->route('tenant.stock-transfers.index')->with('status', $message);
    }

    /**
     * The form field a posting failure belongs on (plan 12, BR12). The
     * transfer's branch is the source store's, so a refused branch belongs
     * on the source store; a refused destination (two branches with the
     * module off, an inactive destination store or branch) on the
     * destination store. Everything else stays under `lines`, where it
     * always was.
     */
    private function errorField(InvalidArgumentException|AuthorizationException $exception): string
    {
        $message = $exception->getMessage();

        // StockTransfer::assertCanDispatchBetweenBranches(): a future-dated
        // dispatch between branches (BR22) belongs to the date field.
        if (str_starts_with($message, 'A transfer between branches cannot be dated')) {
            return 'date';
        }

        foreach (['The source and destination stores are in different branches.', 'The destination store '] as $destinationMessage) {
            if (str_starts_with($message, $destinationMessage)) {
                return 'to_store_id';
            }
        }

        return BranchFormOptions::errorField($exception, 'lines', 'from_store_id', 'from_store_id');
    }

    /**
     * Receives a transfer between branches into its destination store
     * (plan 12, BR18). Gated by `stock_transfers.receive`; the model checks
     * that the user may use the receiving branch, locks the transfer and
     * re-checks it is still in transit, so a double submit cannot receive
     * twice.
     */
    public function receive(Request $request, StockTransfer $stock_transfer): RedirectResponse
    {
        $data = $request->validate([
            'received_on' => ['required', 'date'],
        ]);

        try {
            $stock_transfer->receive($request->user(), $data['received_on']);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['received_on' => $e->getMessage()]);
        }

        return redirect()->route('tenant.stock-transfers.index')->with('status', 'Stock transfer received.');
    }

    public function cancel(Request $request, StockTransfer $stock_transfer): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $stock_transfer->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.stock-transfers.index')->with('status', 'Stock transfer cancelled.');
    }

    /**
     * Streams a printable PDF inline (not a forced download), so it opens in
     * a new browser tab from a plain anchor link on the Index page - same
     * pattern as SaleController::print()/PurchaseController::print(). A
     * stock transfer has no invoice-style numbering sequence of its own (see
     * this class's docblock - it never touches the ledger), so the document
     * number is simply its own id, matching QuotationController::print()'s
     * fallback for a quotation with no reference_number.
     */
    public function print(Request $request, StockTransfer $stock_transfer): HttpResponse
    {
        $stock_transfer->load(['fromStore', 'toStore', 'lines.item']);

        // A transfer between branches prints both branch names (BR18).
        if ($stock_transfer->isCrossBranch()) {
            $stock_transfer->load(['fromBranch:id,name', 'toBranch:id,name']);
        }

        $pdf = Pdf::loadView('pdf.stock-transfer', [
            'stockTransfer' => $stock_transfer,
            'company' => CompanySetting::current(),
            'documentNumber' => "TRF-{$stock_transfer->id}",
            'documentDate' => $stock_transfer->date->format('Y-m-d'),
            // Records this print for the audit trail (CONTRACTS C9), same as
            // every other printable document - see QuotationController::
            // print() for the identical pattern.
            'copyNumber' => PrintLog::record($stock_transfer, $request->user()),
        ]);

        return $pdf->stream("stock-transfer-{$stock_transfer->id}.pdf");
    }
}
