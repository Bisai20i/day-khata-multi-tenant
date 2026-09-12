<?php

namespace App\Http\Controllers\Tenant\Sales;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Agent;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Item;
use App\Models\PrintLog;
use App\Models\Sale;
use App\Models\Store;
use App\Support\AmountInWords;
use App\Support\Billing\BillingException;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SaleController extends Controller
{
    /**
     * Party ledgers (customers, suppliers, agents) live under these
     * subgroups. They are perfectly valid accounts, but never a bank, cash or
     * TDS account, so the settlement pickers exclude them - see
     * settlementAccounts().
     */
    private const PARTY_SUBGROUPS = ['Sundry Debtors', 'Sundry Creditors', 'Sales Agents'];

    /**
     * Listing is server-side filtered (date range + customer) and paginated
     * - same `when()`/`paginate()->withQueryString()` shape
     * Central\Tenants\TenantController::index() established, so it stays
     * consistent across the app rather than loading every sale unfiltered
     * (a real usability problem once invoice history grows).
     */
    public function index(Request $request): Response
    {
        $from = $request->filled('from') ? $request->string('from')->toString() : null;
        $to = $request->filled('to') ? $request->string('to')->toString() : null;
        $customerId = $request->filled('customer_id') ? (int) $request->input('customer_id') : null;

        $settings = CompanySetting::current();

        $sales = Sale::query()
            ->with(['customer:id,name', 'agent:id,name', 'lines.item:id,name,unit'])
            ->when($from, fn ($query, string $from) => $query->whereDate('date', '>=', $from))
            ->when($to, fn ($query, string $to) => $query->whereDate('date', '<=', $to))
            ->when($customerId, fn ($query, int $customerId) => $query->where('customer_id', $customerId))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Tenant/Sales/Index', [
            'sales' => $sales,
            'filters' => [
                'from' => $from,
                'to' => $to,
                'customer_id' => $customerId,
            ],
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'mobile_no']),
            'items' => $this->itemsForPicker(),
            'bankAccounts' => $this->settlementAccounts(),
            'tdsAccounts' => $this->settlementAccounts(),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'agents' => Agent::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'commission_rate']),
            'invoiceSettings' => [
                'default_vat_rate' => $settings->default_vat_rate,
                'default_store_id' => $settings->default_store_id,
                'sale_full_enabled' => (bool) $settings->sale_full_enabled,
                'sale_abbreviated_enabled' => (bool) $settings->sale_abbreviated_enabled,
                'sale_pan_enabled' => (bool) $settings->sale_pan_enabled,
            ],
        ]);
    }

    /**
     * The item picker's payload. `sale_rate` is included so Create.vue can
     * prefill a line's rate the way the POS tiles already do (audit P2
     * "rate autofill: sale_rate not sent to Create"), and `current_stock` is
     * an exact 4dp Quantity string rather than a float, so the browser can
     * render it with formatQuantity() without ever doing decimal arithmetic.
     *
     * Stock here is the cross-store total, deliberately identical to
     * PosController's tiles (Sale::post() still enforces the per-store check
     * at posting time) so the same item never shows two different numbers on
     * two screens.
     *
     * @return Collection<int, Item>
     */
    private function itemsForPicker(): Collection
    {
        $items = Item::query()->where('is_active', true)->orderBy('name')
            ->with(['units' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
            ->get(['id', 'name', 'unit', 'is_vatable', 'is_stockable', 'barcode', 'sale_rate', 'hs_code']);

        $stock = Item::currentStockByItem($items->pluck('id')->all());

        return $items->map(function (Item $item) use ($stock) {
            $item->current_stock = $item->is_stockable
                ? ($stock[$item->id] ?? null)?->toString()
                : null;

            return $item;
        });
    }

    /**
     * Accounts that may hold a settlement: cash, bank and TDS ledgers.
     *
     * The chart of accounts has no dedicated "Bank" or "TDS" group, so
     * eligibility is defined by what an account cannot be: a Profit & Loss
     * account (income or expense) never holds money, and a party ledger
     * (customer, supplier, agent) is the other side of the transaction, not
     * the account it settles into. Picking one of those was the audit's
     * "bank, TDS and refund pickers filtered by account type" gap; the same
     * rule is applied again server-side in store(), since a filtered picker
     * is a convenience, not a control.
     *
     * @return Collection<int, Account>
     */
    private function settlementAccounts(): Collection
    {
        return $this->settlementAccountQuery()->orderBy('name')->get(['id', 'code', 'name']);
    }

    /**
     * @return Builder<Account>
     */
    private function settlementAccountQuery(): Builder
    {
        return Account::query()
            ->where(function (Builder $query) {
                $query->whereHas('group.accountHead', fn (Builder $head) => $head->where('is_profit_and_loss', false))
                    ->orWhereHas('subgroup.accountGroup.accountHead', fn (Builder $head) => $head->where('is_profit_and_loss', false));
            })
            ->whereDoesntHave('subgroup', fn (Builder $subgroup) => $subgroup->whereIn('name', self::PARTY_SUBGROUPS));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->storeRules());

        try {
            $sale = Sale::post(
                Arr::except($data, ['lines']),
                $data['lines'],
                $request->user(),
            );
        } catch (BillingException $e) {
            // The browser previewed one total and the server computed another,
            // so the bill on screen is not the bill being saved (C8). Surfaced
            // on the `expected_total` field rather than as a generic line
            // error, so the form can point at the totals panel.
            if ($e->reason === BillingException::REASON_TOTAL_MISMATCH) {
                throw ValidationException::withMessages([
                    'expected_total' => 'The bill total changed. Please review it before saving.',
                ]);
            }

            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        } catch (AuthorizationException $e) {
            // The fiscal-year guard now lives inside JournalVoucher::write()
            // (C4), and it raises an AuthorizationException - not an
            // InvalidArgumentException - when someone who is not an admin
            // posts into a year that was reopened for correction. Shown on the
            // date field, since the date is what put the bill in that year.
            return back()->withErrors(['date' => $e->getMessage()])->withInput();
        }

        // C11: the posted document identifies itself in the flash, so the page
        // can open its print view directly instead of guessing the newest id
        // out of the list it was redirected to (audit P0-6, which made every
        // POS sale throw and left the posted cart on screen).
        return redirect()->route('tenant.sales.index')
            ->with('status', 'Sale posted.')
            ->with('created', [
                'type' => 'sale',
                'id' => $sale->id,
                'print_url' => route('tenant.sales.print', $sale),
                'receipt' => $this->receiptPayload($sale),
            ]);
    }

    /**
     * The stored sale, shaped for the POS "Sale complete" confirmation.
     *
     * C8 requires a posted document to be rendered from stored server values.
     * The POS used to show a snapshot of what the cart looked like just before
     * submitting, which could differ from the bill that was actually saved -
     * and did, by a paisa, on every bill the old float preview got wrong
     * (P0-8). The change owed is the only figure the browser still works out
     * itself, because cash tendered at the counter is not part of the bill.
     *
     * @return array<string, mixed>
     */
    private function receiptPayload(Sale $sale): array
    {
        $sale->loadMissing(['lines.item:id,name,unit', 'lines.itemUnit:id,name']);

        [$cashSettled, $bankSettled] = match ($sale->payment_mode) {
            'cash' => [Money::of($sale->total)->minus(Money::of($sale->tds_amount)), Money::zero()],
            'bank' => [Money::zero(), Money::of($sale->total)->minus(Money::of($sale->tds_amount))],
            'partial' => [Money::of($sale->cash_amount ?? '0'), Money::of($sale->bank_amount ?? '0')],
            default => [Money::zero(), Money::zero()],
        };

        return [
            'invoice_number' => $sale->invoice_number,
            'date' => $sale->date->format('Y-m-d'),
            'date_bs' => NepaliCalendar::formatBs($sale->date),
            'customer_name' => $sale->buyer_name,
            'payment_mode' => $sale->payment_mode,
            'lines' => $sale->lines->map(fn ($line): array => [
                'name' => $line->item->name,
                'unit' => $line->itemUnit?->name ?? $line->item->unit,
                'quantity' => $line->quantity,
                'rate' => $line->rate,
                'line_total' => $line->line_total,
            ])->all(),
            'taxable_amount' => $sale->taxable_amount,
            'nontaxable_amount' => $sale->nontaxable_amount,
            'vat_amount' => $sale->vat_amount,
            'tds_amount' => $sale->tds_amount,
            'total' => $sale->total,
            'cash_settled' => $cashSettled->toString(),
            'bank_settled' => $bankSettled->toString(),
            'outstanding' => $sale->outstandingAmount()->toString(),
        ];
    }

    /**
     * Validation rules for posting a sale.
     *
     * Decimal places are capped to what the columns actually store (audit
     * P0-5): quantities, rates and conversion factors at 4, money and
     * percentages at 2. Before this, a quantity of 0.00004 at Rs 1,000,000
     * charged Rs 40 and stored a quantity of 0.0000 with no stock movement.
     *
     * `rate` is required rather than defaulted: a blank rate used to become
     * a silent zero, which posts a free line onto a real invoice.
     *
     * `vat_rate` is deliberately absent - the rate always comes from
     * CompanySetting::default_vat_rate (see Sale::post()), never from the
     * browser.
     *
     * @return array<string, mixed>
     */
    private function storeRules(): array
    {
        $settlementAccount = Rule::in($this->settlementAccountQuery()->pluck('id')->all());

        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'invoice_type' => ['required', 'in:abbreviated,full,pan'],
            'chalani_number' => ['nullable', 'string', 'max:100'],
            'date' => ['required', 'date'],
            'payment_mode' => ['required', 'in:cash,bank,partial,credit'],
            'bank_account_id' => ['nullable', 'integer', $settlementAccount],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'discount' => ['nullable', 'decimal:0,2', 'min:0'],
            'discount_type' => ['nullable', 'in:percentage,flat'],
            'cash_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'bank_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'tds_account_id' => ['nullable', 'integer', $settlementAccount],
            'tds_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'agent_id' => ['nullable', 'exists:agents,id'],
            'commission_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'narration' => ['nullable', 'string', 'max:255'],
            'expected_total' => ['nullable', 'decimal:0,2'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            // Null/omitted means the item's own base unit - Sale::post()
            // resolves this against the item's own ItemUnit rows (and
            // rejects a unit that belongs to a different item), so no
            // cross-item ownership check is needed here.
            'lines.*.item_unit_id' => ['nullable', 'integer', 'exists:item_units,id'],
            // Negative allowed on purpose: a negative-quantity line is how
            // this app models an in-bill return/adjustment line (legacy
            // parity) - Sale::post() reduces revenue/VAT by the (negative)
            // line total and, via Item::recordStockMovement()'s fixed
            // StockMovementType::Sale direction, correctly nets the stock
            // effect back to a restock instead of a sale. Only exactly zero
            // is meaningless and rejected.
            'lines.*.quantity' => ['required', 'decimal:0,4', Rule::notIn([0, '0', '0.0', '0.00', '0.000', '0.0000'])],
            'lines.*.rate' => ['required', 'decimal:0,4', 'min:0'],
            'lines.*.discount' => ['nullable', 'decimal:0,2', 'min:0'],
            'lines.*.discount_type' => ['nullable', 'in:percentage,flat'],
        ];
    }

    public function cancel(Request $request, Sale $sale): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $sale->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            // Both shapes the fiscal-year guard can fail with (C4): closed or
            // missing year raises InvalidArgumentException, a non-admin on a
            // reopened year raises AuthorizationException. Either way the
            // cashier gets the reason on the form, not an error page.
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.sales.index')->with('status', 'Sale cancelled.');
    }

    /**
     * Streams a printable PDF invoice inline (not a forced download), so it
     * opens in a new browser tab from a plain anchor link on the Index page.
     *
     * Everything printed comes from the stored row: the invoice number issued
     * at posting, the buyer as they were on the bill, and the totals as they
     * were computed - never re-derived from the current settings or the live
     * customer (C7, C9, audit P0-8 and the "Invoice and IRD compliance"
     * cluster). PrintLog::record() returns the copy number, so a reprint is
     * stamped as a copy rather than passing as a second original.
     */
    public function print(Request $request, Sale $sale): HttpResponse
    {
        $sale->load(['customer', 'agent', 'bankAccount', 'lines.item', 'lines.itemUnit', 'journalVoucher', 'fiscalYear']);

        $company = CompanySetting::current();
        $copyNumber = PrintLog::record($sale, $request->user());

        // Thermal paper sizes get a lightweight narrow-column receipt layout
        // instead of the full A4/A5 letterhead invoice - dompdf has no
        // built-in 58mm/80mm paper preset, so the width is passed as an
        // explicit [x1, y1, x2, y2] point box (1mm ~ 2.83pt) with a generous
        // unbounded height for a continuous thermal roll.
        $isThermal = in_array($company->print_paper_size, ['58mm', '80mm'], true);

        $pdf = Pdf::loadView($isThermal ? 'pdf.sale-receipt' : 'pdf.sale', [
            'sale' => $sale,
            'company' => $company,
            'documentNumber' => $sale->invoice_number ?? "#{$sale->id}",
            'documentDate' => $sale->date->format('Y-m-d'),
            'dateAd' => $sale->date->format('Y-m-d'),
            'dateBs' => NepaliCalendar::formatBs($sale->date),
            'fiscalYearName' => $sale->fiscalYear?->name,
            'copyNumber' => $copyNumber,
            'amountInWords' => AmountInWords::rupees(Money::of($sale->total)),
        ]);

        if ($isThermal) {
            $width = $company->print_paper_size === '58mm' ? 164 : 227;
            $pdf->setPaper([0, 0, $width, 2000]);
        }

        return $pdf->stream("sale-{$sale->id}.pdf");
    }
}
