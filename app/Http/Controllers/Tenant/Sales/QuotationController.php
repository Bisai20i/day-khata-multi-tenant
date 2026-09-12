<?php

namespace App\Http\Controllers\Tenant\Sales;

use App\Enums\QuotationStatus;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\PrintLog;
use App\Models\Quotation;
use App\Support\Billing\BillingException;
use App\Support\Billing\DocumentTotals;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class QuotationController extends Controller
{
    /**
     * Listing is server-side filtered (date range + customer) and paginated
     * - same `when()`/`paginate()->withQueryString()` shape
     * Central\Tenants\TenantController::index() established, so it stays
     * consistent across the app rather than loading every quotation
     * unfiltered (a real usability problem once quotation history grows).
     *
     * The list renders the totals stored on the row. It used to add each
     * quotation up in the browser with its own formula, which is one of the
     * three disagreeing calculations the audit found (P0-9).
     */
    public function index(Request $request): Response
    {
        $from = $request->filled('from') ? $request->string('from')->toString() : null;
        $to = $request->filled('to') ? $request->string('to')->toString() : null;
        $customerId = $request->filled('customer_id') ? (int) $request->input('customer_id') : null;

        $quotations = Quotation::query()
            ->with(['customer:id,name', 'lines.item:id,name,unit', 'sale:id,journal_voucher_id'])
            ->when($from, fn ($query, string $from) => $query->whereDate('date', '>=', $from))
            ->when($to, fn ($query, string $to) => $query->whereDate('date', '<=', $to))
            ->when($customerId, fn ($query, int $customerId) => $query->where('customer_id', $customerId))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Tenant/Quotations/Index', [
            'quotations' => $quotations,
            'filters' => [
                'from' => $from,
                'to' => $to,
                'customer_id' => $customerId,
            ],
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'items' => Item::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'unit', 'is_vatable']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $totals = $this->calculate($data);

        $quotation = Quotation::create([
            ...collect($data)->except(['lines', 'expected_total'])->all(),
            ...Quotation::storedTotals($totals),
            'status' => QuotationStatus::Draft,
            'created_by' => $request->user()->id,
        ]);

        foreach ($data['lines'] as $line) {
            $quotation->lines()->create($line);
        }

        return redirect()->route('tenant.quotations.index')
            ->with('status', 'Quotation saved.')
            ->with('created', [
                'type' => 'quotation',
                'id' => $quotation->id,
                'print_url' => route('tenant.quotations.print', $quotation),
            ]);
    }

    public function update(Request $request, Quotation $quotation): RedirectResponse
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            return back()->withErrors(['quotation' => 'Only a draft quotation can be edited.']);
        }

        $data = $this->validated($request);
        $totals = $this->calculate($data);

        $quotation->update([
            ...collect($data)->except(['lines', 'expected_total'])->all(),
            ...Quotation::storedTotals($totals),
        ]);
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
            $sale = $quotation->convertToSale($request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['quotation' => $e->getMessage()]);
        }

        return redirect()->route('tenant.quotations.index')
            ->with('status', 'Quotation converted to sale.')
            ->with('created', [
                'type' => 'sale',
                'id' => $sale->id,
                'print_url' => route('tenant.sales.print', $sale),
            ]);
    }

    /**
     * Streams a printable PDF quotation inline (not a forced download), so it
     * opens in a new browser tab from a plain anchor link on the Index page.
     *
     * The header figures are the ones stored on the row; only the per-line
     * amounts are recalculated, and they come from DocumentCalculator - the
     * same calculator that wrote those stored columns. The PDF used to do its
     * own float arithmetic and applied VAT to every line whether the item was
     * vatable or not, so a printed quote could not match either the list or
     * the sale it became (audit P0-1, P0-9).
     */
    public function print(Request $request, Quotation $quotation): HttpResponse
    {
        $quotation->load(['customer', 'lines.item']);

        $totals = $quotation->totals();

        $lines = $quotation->lines->values()->map(fn ($line, int $index): array => [
            'item' => $line->item,
            'quantity' => $totals->lines[$index]->quantity,
            'rate' => $totals->lines[$index]->rate,
            'discount' => $totals->lines[$index]->discountAmount,
            'line_total' => $totals->lines[$index]->lineTotal,
        ]);

        $documentNumber = $quotation->reference_number ?: "QUO-{$quotation->id}";
        $documentDate = $quotation->date->format('Y-m-d');

        return Pdf::loadView('pdf.quotation', [
            'quotation' => $quotation,
            'lines' => $lines,
            'subtotal' => $totals->subtotal(),
            'taxable' => Money::of($quotation->taxable_amount),
            'nontaxable' => Money::of($quotation->nontaxable_amount),
            'vat' => Money::of($quotation->vat_amount),
            'total' => Money::of($quotation->total),
            'company' => CompanySetting::current(),
            'documentNumber' => $documentNumber,
            'documentDate' => $documentDate,
            'copyNumber' => PrintLog::record($quotation, $request->user()),
            'dateAd' => $documentDate,
            'dateBs' => NepaliCalendar::formatBs($documentDate),
            'fiscalYearName' => $this->fiscalYearNameFor($documentDate),
        ])->stream("quotation-{$quotation->id}.pdf");
    }

    /**
     * A quotation is not a posted document, so it has no fiscal year of its
     * own; the one printed is simply the year its date falls in, or nothing
     * when no year covers it.
     */
    private function fiscalYearNameFor(string $date): ?string
    {
        return FiscalYear::query()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->value('name');
    }

    /**
     * The one calculation a quotation goes through on the way in.
     *
     * `expected_total` is the total the browser previewed with `money.js`; a
     * difference means the bill on screen was not the bill being saved, so the
     * save is refused rather than quietly storing a different number (C8).
     *
     * @param  array{lines: array<int, array<string, mixed>>, discount: mixed, vat_rate: mixed, expected_total?: string|null}  $data
     */
    private function calculate(array $data): DocumentTotals
    {
        try {
            return Quotation::calculateTotals($data['lines'], [
                'discount' => $data['discount'],
                'vat_rate' => $data['vat_rate'],
                'expected_total' => $data['expected_total'] ?? null,
            ]);
        } catch (BillingException $e) {
            throw ValidationException::withMessages(
                $e->reason === BillingException::REASON_TOTAL_MISMATCH
                    ? ['expected_total' => 'The bill total changed. Please review it before saving.']
                    : ['lines' => $e->getMessage()]
            );
        }
    }

    /**
     * @return array{customer_id: int, date: string, discount: string, vat_rate: string, reference_number: ?string, narration: ?string, expected_total: ?string, lines: array<int, array{item_id: int, quantity: string, rate: string, discount: string}>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'date' => ['required', 'date'],
            'discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'narration' => ['nullable', 'string', 'max:255'],
            'expected_total' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001', 'decimal:0,4'],
            'lines.*.rate' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        // discount/vat_rate/line-discount columns are not nullable (they
        // default to 0/13.00 at the schema level), so a null is coerced here
        // rather than passed through to Eloquent. The values stay strings all
        // the way to DocumentCalculator: casting them to float first is
        // exactly the rounding bug this whole pass exists to remove.
        $data['discount'] = $this->decimalString($data['discount'] ?? null, '0');
        $data['vat_rate'] = $this->decimalString($data['vat_rate'] ?? null, '13');
        $data['lines'] = array_map(
            fn (array $line): array => [
                'item_id' => $line['item_id'],
                'quantity' => (string) $line['quantity'],
                'rate' => (string) $line['rate'],
                'discount' => $this->decimalString($line['discount'] ?? null, '0'),
            ],
            $data['lines'],
        );

        return $data;
    }

    private function decimalString(mixed $value, string $default): string
    {
        return ($value === null || $value === '') ? $default : (string) $value;
    }
}
