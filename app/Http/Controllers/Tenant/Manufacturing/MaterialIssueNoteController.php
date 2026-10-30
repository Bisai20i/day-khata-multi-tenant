<?php

namespace App\Http\Controllers\Tenant\Manufacturing;

use App\Enums\FiscalYearStatus;
use App\Enums\InventoryClass;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Manufacturing\StoreMaterialIssueNoteRequest;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\MaterialIssueNote;
use App\Models\PrintLog;
use App\Models\Store;
use App\Support\AmountInWords;
use App\Support\Inventory\PerpetualCosting;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class MaterialIssueNoteController extends Controller
{
    /**
     * Paginated list, filtered on the server by date window and status. The
     * window defaults to the open fiscal year so the page never loads a
     * tenant's whole history.
     */
    public function index(Request $request): Response
    {
        $openYear = FiscalYear::where('status', FiscalYearStatus::Open)->first();

        $from = $request->string('from')->toString() ?: $openYear?->start_date?->toDateString();
        $to = $request->string('to')->toString() ?: $openYear?->end_date?->toDateString();
        $status = $request->string('status')->toString();

        return Inertia::render('Tenant/Manufacturing/MaterialIssues/Index', [
            'notes' => MaterialIssueNote::query()
                ->with(['store:id,name', 'productionEntry:id,number'])
                ->when($from, fn ($query) => $query->whereDate('date', '>=', $from))
                ->when($to, fn ($query) => $query->whereDate('date', '<=', $to))
                ->when(in_array($status, [MaterialIssueNote::STATUS_POSTED, MaterialIssueNote::STATUS_CANCELLED], true), fn ($query) => $query->where('status', $status))
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
            'filters' => ['from' => $from, 'to' => $to, 'status' => $status ?: null],
        ]);
    }

    /**
     * Raw materials only, each with the store-by-store on-hand quantity and
     * today's moving-average rate (a preview: the server costs the issue
     * under the item locks and its figure is the one that posts).
     */
    public function create(): Response
    {
        $today = now()->setTimezone('Asia/Kathmandu')->toDateString();
        $stores = Store::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        $items = Item::query()
            ->where('is_stockable', true)
            ->where('inventory_class', InventoryClass::RawMaterial->value)
            ->orderBy('name')
            ->with(['units' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
            ->get();

        $onHandByStore = [];

        foreach ($stores as $store) {
            $onHandByStore[$store->id] = Item::currentStockByItem($items->pluck('id')->all(), $store->id);
        }

        return Inertia::render('Tenant/Manufacturing/MaterialIssues/Create', [
            'stores' => $stores,
            'items' => $items->map(fn (Item $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'unit' => $item->unit,
                'units' => $item->units->map(fn ($unit) => [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'conversion_factor' => (string) $unit->conversion_factor,
                ])->values(),
                'on_hand' => collect($stores)->mapWithKeys(fn ($store) => [
                    $store->id => ($onHandByStore[$store->id][$item->id] ?? null)?->toString() ?? '0.0000',
                ]),
                'average_rate' => PerpetualCosting::movingAverage($item, $today)?->toScale(4, RoundingMode::HalfUp)->__toString(),
            ])->values(),
            'correctionFiscalYear' => $this->correctionFiscalYear(),
        ]);
    }

    /**
     * The one closed year currently reopened for correction, with its date
     * range as plain Y-m-d strings so the page can ask for the correction
     * reason when the typed date falls inside it. The guard in
     * MaterialIssueNote::post() still resolves the year from the date.
     *
     * @return array{id: int, name: string, start_date: string, end_date: string}|null
     */
    private function correctionFiscalYear(): ?array
    {
        $year = FiscalYear::openForCorrection();

        return $year === null ? null : [
            'id' => (int) $year->id,
            'name' => (string) $year->name,
            'start_date' => $year->start_date->toDateString(),
            'end_date' => $year->end_date->toDateString(),
        ];
    }

    public function store(StoreMaterialIssueNoteRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $note = MaterialIssueNote::post(
                collect($data)->only(['date', 'store_id', 'production_entry_id', 'narration', 'reason'])->all(),
                $data['lines'],
                $request->user(),
            );
        } catch (InvalidArgumentException|AuthorizationException $e) {
            // Both: the closed-year guard throws AuthorizationException for a
            // non-admin aiming at a reopened year, and letting it escape would
            // render a 403 page instead of a field error.
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.material-issues.show', $note)->with('status', "Material issue note MI-{$note->number} posted.");
    }

    public function show(MaterialIssueNote $material_issue): Response
    {
        $material_issue->load([
            'store:id,name',
            'productionEntry:id,number',
            'items.item:id,name,unit',
            'items.itemUnit:id,name',
            'creator:id,name',
            'canceller:id,name',
        ]);

        return Inertia::render('Tenant/Manufacturing/MaterialIssues/Show', [
            'note' => $material_issue,
        ]);
    }

    public function cancel(Request $request, MaterialIssueNote $material_issue): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $material_issue->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.material-issues.show', $material_issue)->with('status', 'Material issue note cancelled.');
    }

    /**
     * Streams the note inline and records the print (CONTRACTS C9), same as
     * every other printable document.
     */
    public function print(Request $request, MaterialIssueNote $material_issue): HttpResponse
    {
        $material_issue->load(['store', 'productionEntry', 'items.item', 'items.itemUnit', 'fiscalYear']);

        $pdf = Pdf::loadView('pdf.material-issue-note', [
            'note' => $material_issue,
            'company' => CompanySetting::current(),
            'documentNumber' => "MI-{$material_issue->number}",
            'documentDate' => $material_issue->date->format('Y-m-d'),
            'dateAd' => $material_issue->date->format('Y-m-d'),
            'dateBs' => NepaliCalendar::formatBs($material_issue->date),
            'fiscalYearName' => $material_issue->fiscalYear?->name,
            'amountInWords' => AmountInWords::rupees(Money::of($material_issue->total_cost)),
            'copyNumber' => PrintLog::record($material_issue, $request->user()),
        ]);

        return $pdf->stream("material-issue-{$material_issue->number}.pdf");
    }
}
