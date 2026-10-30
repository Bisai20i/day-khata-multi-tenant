<?php

namespace App\Http\Controllers\Tenant\Manufacturing;

use App\Enums\DirectCostType;
use App\Enums\InventoryClass;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Manufacturing\AttachIssueNotesRequest;
use App\Http\Requests\Tenant\Manufacturing\CompleteProductionRequest;
use App\Http\Requests\Tenant\Manufacturing\DirectCostRequest;
use App\Http\Requests\Tenant\Manufacturing\ProductionReasonRequest;
use App\Http\Requests\Tenant\Manufacturing\ReturnMaterialRequest;
use App\Http\Requests\Tenant\Manufacturing\StoreProductionEntryRequest;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalVoucher;
use App\Models\MaterialIssueNote;
use App\Models\PrintLog;
use App\Models\ProductionCompletion;
use App\Models\ProductionDirectCost;
use App\Models\ProductionEntry;
use App\Models\ProductionWipEntry;
use App\Models\Store;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Production entries (MC6): list, create from posted issue notes, and the
 * entry's whole life on one Show page (direct costs, material returns,
 * partial/final completions, cancelling the latest completion or return,
 * abandon, cancel, printable cost sheet). Every posting action forwards the
 * optional correction `reason` for a date in a year reopened for correction.
 *
 * Every state change lives on ProductionEntry; this controller only
 * validates, maps domain exceptions to form errors and shapes props. Money
 * and quantities travel as the strings the model returns, never as numbers.
 * Authorization is each route's can:production_entries.* gate.
 */
class ProductionEntryController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $filters = [
            'status' => $request->filled('status') ? $request->string('status')->toString() : null,
            'from' => $request->filled('from') ? $request->string('from')->toString() : null,
            'to' => $request->filled('to') ? $request->string('to')->toString() : null,
        ];

        $entries = ProductionEntry::query()
            ->with(['finishedItem:id,name,unit', 'store:id,name'])
            ->when(
                in_array($filters['status'], $this->statuses(), true),
                fn ($query) => $query->where('status', $filters['status']),
            )
            ->when($filters['from'], fn ($query, $from) => $query->whereDate('date', '>=', $from))
            ->when($filters['to'], fn ($query, $to) => $query->whereDate('date', '<=', $to))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Tenant/Manufacturing/Production/Index', [
            'entries' => $entries,
            'filters' => $filters,
            'statuses' => $this->statuses(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Tenant/Manufacturing/Production/Create', [
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'finishedItems' => Item::query()
                ->where('inventory_class', InventoryClass::FinishedGood->value)
                ->orderBy('name')
                ->get(['id', 'name', 'unit']),
            // Every unattached posted note with its lines: the page filters by
            // the chosen store client-side, so picking a store never needs a
            // round trip and the lines are already on screen to review.
            'issueNotes' => $this->unattachedNotes(),
            'creditAccounts' => $this->creditAccounts(),
            'costTypes' => $this->costTypes(),
            'correctionFiscalYear' => self::correctionFiscalYear(),
        ]);
    }

    public function store(StoreProductionEntryRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            // One transaction around the entry and its up-front direct
            // costs: a rejected cost must not leave a half-made entry that
            // already holds the issue notes.
            // The one correction reason covers the entry and its up-front
            // costs: they are all dated into the same reopened year or none.
            $entry = DB::transaction(function () use ($data, $request) {
                $entry = ProductionEntry::create(
                    Arr::only($data, ['date', 'store_id', 'finished_item_id', 'planned_quantity', 'narration', 'reason']),
                    array_map('intval', $data['material_issue_note_ids']),
                    $request->user(),
                );

                foreach ($data['direct_costs'] ?? [] as $cost) {
                    $entry->addDirectCost($this->costPayload($cost, $data['reason'] ?? null), $request->user());
                }

                return $entry;
            });
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['material_issue_note_ids' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.production-entries.show', $entry)
            ->with('status', 'Production entry created.');
    }

    public function show(ProductionEntry $production_entry): Response
    {
        $entry = $production_entry->load([
            'finishedItem:id,name,unit',
            'store:id,name',
            'materialIssueNotes.items.item:id,name,unit',
            'directCosts.creditAccount:id,name',
            'completions',
        ]);

        $sheet = $this->normalizedCostSheet($entry);
        $returns = $this->materialReturns($entry);

        return Inertia::render('Tenant/Manufacturing/Production/Show', [
            'entry' => $entry,
            'costSheet' => $sheet,
            'timeline' => $this->timeline($entry, $returns),
            'availableNotes' => $entry->status === ProductionEntry::STATUS_IN_PROGRESS
                ? $this->unattachedNotes($entry->store_id)
                : [],
            'creditAccounts' => $this->creditAccounts(),
            'costTypes' => $this->costTypes(),
            'materialReturns' => $returns,
            'cancellableCompletionId' => $this->cancellableCompletionId($entry),
            'correctionFiscalYear' => self::correctionFiscalYear(),
        ]);
    }

    public function attachNotes(AttachIssueNotesRequest $request, ProductionEntry $production_entry): RedirectResponse
    {
        return $this->perform(
            'material_issue_note_ids',
            'Issue notes attached.',
            $production_entry,
            fn () => $production_entry->attachIssueNotes(
                array_map('intval', $request->validated()['material_issue_note_ids']),
                $request->user(),
            ),
        );
    }

    public function addDirectCost(DirectCostRequest $request, ProductionEntry $production_entry): RedirectResponse
    {
        return $this->perform(
            'amount',
            'Direct cost added.',
            $production_entry,
            fn () => $production_entry->addDirectCost(
                $this->costPayload($request->validated(), $request->validated()['reason'] ?? null),
                $request->user(),
            ),
        );
    }

    public function cancelDirectCost(
        ProductionReasonRequest $request,
        ProductionEntry $production_entry,
        ProductionDirectCost $direct_cost,
    ): RedirectResponse {
        // Scoped to the entry in the URL: a cost id from another entry must
        // not be cancellable through this one.
        abort_unless($direct_cost->production_entry_id === $production_entry->id, 404);

        return $this->perform(
            'reason',
            'Direct cost cancelled.',
            $production_entry,
            fn () => $production_entry->cancelDirectCost($direct_cost, $request->user(), $request->validated()['reason']),
        );
    }

    public function returnMaterial(ReturnMaterialRequest $request, ProductionEntry $production_entry): RedirectResponse
    {
        $data = $request->validated();

        return $this->perform(
            'quantity',
            'Material returned.',
            $production_entry,
            fn () => $production_entry->returnMaterial((int) $data['item_id'], (string) $data['quantity'], $data['date'], $request->user(), $data['reason'] ?? null),
        );
    }

    /**
     * Cancels one material return, addressed by the voucher it posted (a
     * return has no document row of its own). The model checks the voucher
     * belongs to this entry and is its latest live return.
     */
    public function cancelReturn(
        ProductionReasonRequest $request,
        ProductionEntry $production_entry,
        JournalVoucher $return_voucher,
    ): RedirectResponse {
        return $this->perform(
            'reason',
            'Material return cancelled.',
            $production_entry,
            fn () => $production_entry->cancelMaterialReturn($return_voucher, $request->user(), $request->validated()['reason']),
        );
    }

    public function complete(CompleteProductionRequest $request, ProductionEntry $production_entry): RedirectResponse
    {
        $data = $request->validated();

        return $this->perform(
            'quantity',
            $request->boolean('is_final') ? 'Production completed.' : 'Partial output recorded.',
            $production_entry,
            fn () => $production_entry->complete($data['date'], (string) $data['quantity'], $request->boolean('is_final'), $request->user(), $data['reason'] ?? null),
        );
    }

    public function cancelCompletion(
        ProductionReasonRequest $request,
        ProductionEntry $production_entry,
        ProductionCompletion $completion,
    ): RedirectResponse {
        // Scoped to the entry in the URL, like cancelDirectCost().
        abort_unless((int) $completion->production_entry_id === (int) $production_entry->id, 404);

        return $this->perform(
            'reason',
            $completion->is_final ? 'Final completion cancelled; the entry is in progress again.' : 'Completion cancelled.',
            $production_entry,
            fn () => $production_entry->cancelCompletion($completion, $request->user(), $request->validated()['reason']),
        );
    }

    public function abandon(ProductionReasonRequest $request, ProductionEntry $production_entry): RedirectResponse
    {
        return $this->perform(
            'reason',
            'Production abandoned and the remaining WIP written off.',
            $production_entry,
            fn () => $production_entry->abandon($request->user(), $request->validated()['reason']),
        );
    }

    public function cancel(ProductionReasonRequest $request, ProductionEntry $production_entry): RedirectResponse
    {
        return $this->perform(
            'reason',
            'Production entry cancelled.',
            $production_entry,
            fn () => $production_entry->cancel($request->user(), $request->validated()['reason']),
        );
    }

    /**
     * Streams the cost sheet inline so it opens in a new tab from a plain
     * anchor, and logs the print (CONTRACTS C9) like every other document.
     */
    public function print(Request $request, ProductionEntry $production_entry): HttpResponse
    {
        $entry = $production_entry->load([
            'finishedItem:id,name,unit',
            'store:id,name',
            'completions',
        ]);

        $pdf = Pdf::loadView('pdf.production-cost-sheet', [
            'entry' => $entry,
            'sheet' => $this->normalizedCostSheet($entry),
            'company' => CompanySetting::current(),
            'documentNumber' => $this->documentNumber($entry),
            'documentDate' => $entry->date->format('Y-m-d'),
            'copyNumber' => PrintLog::record($entry, $request->user()),
        ]);

        return $pdf->stream("production-cost-sheet-{$entry->number}.pdf");
    }

    /**
     * Runs a model action, turning the domain's InvalidArgumentException /
     * AuthorizationException (closed year, over-return, wrong status, ...)
     * into an error on the field the dialog shows. ValidationException is
     * left to Laravel, which already redirects back with its own keys.
     *
     * @param  callable(): mixed  $action
     */
    private function perform(string $errorKey, string $status, ProductionEntry $entry, callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors([$errorKey => $e->getMessage()]);
        }

        return redirect()->route('tenant.production-entries.show', $entry)->with('status', $status);
    }

    /**
     * @param  array<string, mixed>  $cost
     * @return array{date: string, cost_type: string, description: string, amount: string, credit_account_id: int, reason: string|null}
     */
    private function costPayload(array $cost, ?string $reason = null): array
    {
        return [
            'date' => $cost['date'],
            'cost_type' => $cost['cost_type'],
            'description' => (string) ($cost['description'] ?? ''),
            'amount' => (string) $cost['amount'],
            'credit_account_id' => (int) $cost['credit_account_id'],
            'reason' => $reason,
        ];
    }

    /**
     * The one closed year currently reopened for correction, with its date
     * range as plain Y-m-d strings so the page can tell whether a typed date
     * falls inside it and ask for the correction reason. The server still
     * decides: the guard resolves the year from the date itself.
     *
     * @return array{id: int, name: string, start_date: string, end_date: string}|null
     */
    private static function correctionFiscalYear(): ?array
    {
        $year = FiscalYear::openForCorrection();

        return $year === null ? null : [
            'id' => (int) $year->id,
            'name' => (string) $year->name,
            'start_date' => $year->start_date->toDateString(),
            'end_date' => $year->end_date->toDateString(),
        ];
    }

    /**
     * Every material return of the entry (its `material_out` WIP rows, each
     * pointing at the voucher it posted), oldest first, with the voucher id
     * the cancel route takes and whether this is the one return that can be
     * cancelled right now (the latest live one, while the entry is in
     * progress). The model re-checks all of it under the entry lock.
     *
     * @return list<array{voucher_id: int, date: string, item_name: string, unit: string|null, quantity: string, value: string, cancelled: bool, cancellable: bool}>
     */
    private function materialReturns(ProductionEntry $entry): array
    {
        $rows = $entry->wipEntries()
            ->where('kind', ProductionWipEntry::KIND_MATERIAL_OUT)
            ->where('source_type', (new JournalVoucher)->getMorphClass())
            ->with('item:id,name,unit')
            ->orderBy('id')
            ->get();

        $latestLiveId = $entry->status === ProductionEntry::STATUS_IN_PROGRESS
            ? $rows->where('cancelled', false)->last()?->id
            : null;

        return $rows->map(fn (ProductionWipEntry $row) => [
            'voucher_id' => (int) $row->source_id,
            'date' => $row->date->format('Y-m-d'),
            'item_name' => (string) ($row->item?->name ?? "#{$row->item_id}"),
            'unit' => $row->item?->unit,
            'quantity' => (string) $row->quantity,
            'value' => (string) $row->value,
            'cancelled' => (bool) $row->cancelled,
            'cancellable' => $latestLiveId !== null && $row->id === $latestLiveId,
        ])->values()->all();
    }

    /**
     * The id of the completion that can be cancelled right now: the latest
     * live one, when the entry is in progress (a partial completion) or
     * completed by it (the final one). Null otherwise. The model re-checks.
     */
    private function cancellableCompletionId(ProductionEntry $entry): ?int
    {
        /** @var ProductionCompletion|null $latest */
        $latest = $entry->completions->whereNull('cancelled_at')->sortBy('id')->last();

        if ($latest === null) {
            return null;
        }

        $expected = $latest->is_final ? ProductionEntry::STATUS_COMPLETED : ProductionEntry::STATUS_IN_PROGRESS;

        return $entry->status === $expected ? (int) $latest->id : null;
    }

    /**
     * Posted notes not yet attached to any entry, with their lines.
     *
     * @return list<array<string, mixed>>
     */
    private function unattachedNotes(?int $storeId = null): array
    {
        return MaterialIssueNote::query()
            ->where('status', MaterialIssueNote::STATUS_POSTED)
            ->whereNull('production_entry_id')
            ->when($storeId, fn ($query, $store) => $query->where('store_id', $store))
            ->with(['items.item:id,name,unit'])
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (MaterialIssueNote $note) => [
                'id' => $note->id,
                'number' => $note->number,
                'date' => $note->date->format('Y-m-d'),
                'store_id' => $note->store_id,
                'total_cost' => $note->total_cost,
                'narration' => $note->narration,
                'items' => $note->items->map(fn ($line) => [
                    'id' => $line->id,
                    'item_name' => $line->item?->name,
                    'unit' => $line->item?->unit,
                    'quantity' => $line->quantity,
                    'rate' => $line->unit_cost_rate,
                    'amount' => $line->line_cost,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, code: ?string}>
     */
    private function creditAccounts(): array
    {
        return DirectCostRequest::creditAccountQuery()
            ->orderBy('name')
            ->get(['accounts.id', 'accounts.code', 'accounts.name'])
            ->map(fn ($account) => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name])
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function costTypes(): array
    {
        return array_map(
            fn (DirectCostType $type) => ['value' => $type->value, 'label' => $type->label()],
            DirectCostType::cases(),
        );
    }

    /**
     * @return list<string>
     */
    private function statuses(): array
    {
        return [
            ProductionEntry::STATUS_IN_PROGRESS,
            ProductionEntry::STATUS_COMPLETED,
            ProductionEntry::STATUS_ABANDONED,
            ProductionEntry::STATUS_CANCELLED,
        ];
    }

    private function documentNumber(ProductionEntry $entry): string
    {
        return "PE-{$entry->number}";
    }

    /**
     * ProductionEntry::costSheet() is the single source of the figures; this
     * only pins a stable prop shape (all strings, missing keys become zero or
     * empty) so the page and the PDF never break on an absent key.
     *
     * @return array{materials: list<array<string, mixed>>, direct_costs: list<array{type: string, label: string, amount: string}>, total_production_cost: string, completed_quantity: string, cost_per_unit: string, wip_balance: string}
     */
    private function normalizedCostSheet(ProductionEntry $entry): array
    {
        $sheet = $entry->costSheet();

        $materials = [];
        foreach ((array) ($sheet['materials'] ?? []) as $itemId => $row) {
            $row = (array) $row;
            $materials[] = [
                'item_id' => $row['item_id'] ?? (is_int($itemId) ? $itemId : null),
                'item_name' => $row['item_name'] ?? $row['name'] ?? '-',
                'unit' => $row['unit'] ?? null,
                'issued_quantity' => (string) ($row['issued_quantity'] ?? $row['issued_qty'] ?? $row['issued'] ?? '0'),
                'returned_quantity' => (string) ($row['returned_quantity'] ?? $row['returned_qty'] ?? $row['returned'] ?? '0'),
                'net_quantity' => (string) ($row['net_quantity'] ?? $row['net_qty'] ?? '0'),
                'net_cost' => (string) ($row['net_cost'] ?? $row['cost'] ?? '0.00'),
            ];
        }

        $directCosts = [];
        foreach ((array) ($sheet['direct_costs'] ?? []) as $key => $row) {
            $type = is_array($row) ? (string) ($row['type'] ?? $row['cost_type'] ?? $key) : (string) $key;
            $amount = is_array($row) ? (string) ($row['amount'] ?? $row['total'] ?? '0.00') : (string) $row;
            $directCosts[] = [
                'type' => $type,
                'label' => DirectCostType::tryFrom($type)?->label() ?? ucfirst($type),
                'amount' => $amount,
            ];
        }

        return [
            'materials' => $materials,
            'direct_costs' => $directCosts,
            'total_production_cost' => (string) ($sheet['total_production_cost'] ?? '0.00'),
            'completed_quantity' => (string) ($sheet['completed_quantity'] ?? '0.0000'),
            'cost_per_unit' => (string) ($sheet['cost_per_unit'] ?? '0.0000'),
            'wip_balance' => (string) ($sheet['wip_balance'] ?? '0.00'),
        ];
    }

    /**
     * Chronological events for the Show page. Cancelled rows stay listed
     * (struck through by the page) because the audit trail must show them.
     *
     * @param  list<array{voucher_id: int, date: string, item_name: string, unit: string|null, quantity: string, value: string, cancelled: bool, cancellable: bool}>  $returns
     * @return list<array{date: string, kind: string, label: string, amount: ?string, cancelled: bool}>
     */
    private function timeline(ProductionEntry $entry, array $returns = []): array
    {
        $events = [[
            'date' => $entry->date->format('Y-m-d'),
            'kind' => 'created',
            'label' => 'Production entry created',
            'amount' => null,
            'cancelled' => false,
        ]];

        foreach ($entry->materialIssueNotes as $note) {
            $events[] = [
                'date' => $note->date->format('Y-m-d'),
                'kind' => 'material',
                'label' => "Material issue note {$note->number}",
                'amount' => $note->total_cost,
                'cancelled' => $note->status === MaterialIssueNote::STATUS_CANCELLED,
            ];
        }

        foreach ($entry->directCosts as $cost) {
            $events[] = [
                'date' => $cost->date->format('Y-m-d'),
                'kind' => 'direct_cost',
                'label' => $cost->cost_type->label().' cost'.($cost->description ? ": {$cost->description}" : ''),
                'amount' => $cost->amount,
                'cancelled' => $cost->cancelled_at !== null,
            ];
        }

        foreach ($returns as $return) {
            $events[] = [
                'date' => $return['date'],
                'kind' => 'return',
                'label' => "Material return of {$return['quantity']} {$return['item_name']}",
                'amount' => $return['value'],
                'cancelled' => $return['cancelled'],
            ];
        }

        foreach ($entry->completions as $completion) {
            $events[] = [
                'date' => $completion->date->format('Y-m-d'),
                'kind' => 'completion',
                'label' => ($completion->is_final ? 'Final' : 'Partial').' completion of '.$completion->quantity,
                'amount' => $completion->cost,
                'cancelled' => $completion->cancelled_at !== null,
            ];
        }

        if ($entry->status !== ProductionEntry::STATUS_IN_PROGRESS) {
            $closedAt = $entry->cancelled_at ?? $entry->completed_at ?? $entry->updated_at;
            $events[] = [
                'date' => $closedAt->format('Y-m-d'),
                'kind' => 'closed',
                'label' => match ($entry->status) {
                    ProductionEntry::STATUS_CANCELLED => 'Cancelled: '.$entry->cancel_reason,
                    ProductionEntry::STATUS_ABANDONED => 'Abandoned: '.$entry->cancel_reason,
                    default => 'Marked completed',
                },
                'amount' => null,
                'cancelled' => false,
            ];
        }

        usort($events, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        return $events;
    }
}
