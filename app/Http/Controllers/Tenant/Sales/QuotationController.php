<?php

namespace App\Http\Controllers\Tenant\Sales;

use App\Enums\QuotationStatus;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Quotation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class QuotationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Quotations/Index', [
            'quotations' => Quotation::query()
                ->with(['customer:id,name', 'lines.item:id,name,unit', 'sale:id,journal_voucher_id'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'items' => Item::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'unit', 'is_vatable']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $quotation = Quotation::create([
            ...collect($data)->except('lines')->all(),
            'status' => QuotationStatus::Draft,
            'created_by' => $request->user()->id,
        ]);

        foreach ($data['lines'] as $line) {
            $quotation->lines()->create($line);
        }

        return redirect()->route('tenant.quotations.index')->with('status', 'Quotation saved.');
    }

    public function update(Request $request, Quotation $quotation): RedirectResponse
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            return back()->withErrors(['quotation' => 'Only a draft quotation can be edited.']);
        }

        $data = $this->validated($request);

        $quotation->update(collect($data)->except('lines')->all());
        $quotation->lines()->delete();

        foreach ($data['lines'] as $line) {
            $quotation->lines()->create($line);
        }

        return redirect()->route('tenant.quotations.index')->with('status', 'Quotation updated.');
    }

    public function destroy(Quotation $quotation): RedirectResponse
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            return back()->withErrors(['quotation' => 'Only a draft quotation can be deleted.']);
        }

        $quotation->delete();

        return redirect()->route('tenant.quotations.index')->with('status', 'Quotation deleted.');
    }

    public function cancel(Quotation $quotation): RedirectResponse
    {
        try {
            $quotation->cancel();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['quotation' => $e->getMessage()]);
        }

        return redirect()->route('tenant.quotations.index')->with('status', 'Quotation cancelled.');
    }

    public function convertToSale(Request $request, Quotation $quotation): RedirectResponse
    {
        try {
            $quotation->convertToSale($request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['quotation' => $e->getMessage()]);
        }

        return redirect()->route('tenant.quotations.index')->with('status', 'Quotation converted to sale.');
    }

    /**
     * Streams a printable PDF quotation inline (not a forced download), so it
     * opens in a new browser tab from a plain anchor link on the Index page.
     *
     * A quotation stores no totals of its own (no line_total on QuotationLine,
     * no taxable_amount/vat_amount/total on Quotation) and never posts to the
     * ledger, so every figure below is computed here from the raw quantity/
     * rate/discount/vat_rate columns using the exact same formula as the
     * Index page's own quotationTotal() - it applies VAT to the whole
     * discounted line total, unlike Sale/Purchase's taxable/nontaxable split.
     */
    public function print(Quotation $quotation): HttpResponse
    {
        $quotation->load(['customer', 'lines.item']);

        $lines = $quotation->lines->map(function ($line) {
            $lineTotal = round((float) $line->quantity * (float) $line->rate - (float) $line->discount, 2);

            return [
                'item' => $line->item,
                'quantity' => (float) $line->quantity,
                'rate' => (float) $line->rate,
                'discount' => (float) $line->discount,
                'line_total' => $lineTotal,
            ];
        });

        $lineSum = round((float) $lines->sum('line_total'), 2);
        $taxable = round($lineSum - (float) $quotation->discount, 2);
        $vat = round($taxable * ((float) $quotation->vat_rate / 100), 2);
        $total = round($taxable + $vat, 2);

        $documentNumber = $quotation->reference_number ?: "QUO-{$quotation->id}";

        return Pdf::loadView('pdf.quotation', [
            'quotation' => $quotation,
            'lines' => $lines,
            'lineSum' => $lineSum,
            'taxable' => $taxable,
            'vat' => $vat,
            'total' => $total,
            'company' => CompanySetting::current(),
            'documentNumber' => $documentNumber,
            'documentDate' => $quotation->date->format('Y-m-d'),
        ])->stream("quotation-{$quotation->id}.pdf");
    }

    /**
     * @return array{customer_id: int, date: string, discount: float, vat_rate: float, reference_number: ?string, narration: ?string, lines: array<int, array{item_id: int, quantity: float, rate: float, discount: float}>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'date' => ['required', 'date'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'vat_rate' => ['nullable', 'numeric', 'min:0'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'narration' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        // discount/vat_rate/line-discount columns are not nullable (they
        // default to 0/13.00 at the schema level) - coerce here rather than
        // letting a null through to Eloquent, matching Sale::post()'s own
        // (float) ($data['x'] ?? 0) coercion for the same fields.
        $data['discount'] = (float) ($data['discount'] ?? 0);
        $data['vat_rate'] = (float) ($data['vat_rate'] ?? 13.00);
        $data['lines'] = array_map(
            fn (array $line) => [...$line, 'discount' => (float) ($line['discount'] ?? 0)],
            $data['lines'],
        );

        return $data;
    }
}
