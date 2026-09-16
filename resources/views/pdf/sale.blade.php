@extends('pdf.layout')

@php
    use App\Support\Money\Money;
    use App\Support\Money\Quantity;

    $isAbbreviated = $sale->invoice_type === 'abbreviated';
    $isPan = $sale->invoice_type === 'pan';
    $hidesVatBreakdown = $isAbbreviated || $isPan;

    // Everything below is read from the stored row, never recomputed: the
    // 2026-09-11 audit found the printed bill disagreeing with the stored one
    // (P0-8) and the taxable line printing before the discount was applied.
    $headerDiscount = Money::of($sale->discount_amount);
    $taxable = Money::of($sale->taxable_amount);
    $nontaxable = Money::of($sale->nontaxable_amount);
    $vat = Money::of($sale->vat_amount);
    $tds = Money::of($sale->tds_amount);
    $total = Money::of($sale->total);

    // The subtotal is what the lines came to before the header discount, which
    // is exactly the two group totals plus the discount that was taken off
    // them (C3 steps 4 and 5).
    $subtotal = $taxable->plus($nontaxable)->plus($headerDiscount);
    $netReceivable = $total->minus($tds);

    // Buyer as the invoice was issued to (C7). Old rows have no snapshot, so
    // they fall back to the live customer, which is what they always printed.
    $buyerName = $sale->buyer_name ?? $sale->customer->name;
    $buyerPan = $sale->buyer_pan ?? $sale->customer->tpin;
    $buyerAddress = $sale->buyer_address ?? $sale->customer->address;

    $anyHsCode = $sale->lines->contains(fn ($line) => filled($line->item->hs_code));

    // Bonus / free units (audit section 3 "Sales"). The column only appears on
    // a bill that actually gave some away, so every bill printed until now
    // still prints exactly as it did. The customer has to see the free pieces
    // on the invoice: they left the shop, they are in the stock ledger, and
    // they are what a later return is checked against - they are simply
    // charged at nothing, which is why the Amount column is unaffected.
    $anyBonus = $sale->lines->contains(fn ($line) => Quantity::of($line->bonus_quantity ?? '0')->isPositive());
@endphp

@section('title', $isAbbreviated ? 'Abbreviated Tax Invoice' : ($isPan ? 'PAN Invoice' : 'Tax Invoice'))

@section('doc-meta-extra')
    <div><strong>Payment:</strong> {{ ucfirst($sale->payment_mode) }}</div>
    @if($sale->chalani_number)
        <div><strong>Chalani:</strong> {{ $sale->chalani_number }}</div>
    @endif
    @if($sale->status === 'cancelled')
        <div style="margin-top: 4px;"><span class="status-badge">Cancelled</span></div>
    @endif
@endsection

@section('content')
    @unless($isAbbreviated)
        <table class="party-table">
            <tr>
                <td>
                    <div class="party-label">Bill To</div>
                    <div class="party-name">{{ $buyerName }}</div>
                    @if($buyerAddress)
                        <div>{{ $buyerAddress }}</div>
                    @endif
                    @if($sale->customer->mobile_no)
                        <div>Mobile: {{ $sale->customer->mobile_no }}</div>
                    @endif
                    @if($buyerPan)
                        <div>PAN/VAT: {{ $buyerPan }}</div>
                    @endif
                </td>
                <td class="text-right">
                    @if($sale->agent)
                        <div>Agent: {{ $sale->agent->name }}</div>
                    @endif
                    @if($sale->payment_mode === 'bank' && $sale->bankAccount)
                        <div>Bank Account: {{ $sale->bankAccount->name }}</div>
                    @endif
                </td>
            </tr>
        </table>
    @endunless

    @if($isAbbreviated)
        {{-- IRD-prescribed boxed/segmented PAN digits for the seller, since an
             abbreviated tax invoice carries no buyer information at all. --}}
        <div class="pan-section">
            <div class="pan-label">Taxpayer's PAN:</div>
            <div class="pan-box-wrapper">
                @foreach(str_split(str_pad((string) $company->pan_vat_number, 9, ' ', STR_PAD_LEFT)) as $digit)
                    <div class="pan-box">{{ $digit }}</div>
                @endforeach
            </div>
        </div>
    @endif

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">S.N.</th>
                <th>Item</th>
                @if($anyHsCode)
                    <th style="width: 10%;">HS Code</th>
                @endif
                <th style="width: 8%;">Unit</th>
                <th class="text-right" style="width: 10%;">Qty</th>
                @if($anyBonus)
                    <th class="text-right" style="width: 8%;">Free</th>
                @endif
                <th class="text-right" style="width: 12%;">Rate</th>
                <th class="text-right" style="width: 12%;">Discount</th>
                <th class="text-right" style="width: 14%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->lines as $index => $line)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $line->item->name }}</td>
                    @if($anyHsCode)
                        <td>{{ $line->item->hs_code ?? '' }}</td>
                    @endif
                    {{-- The unit the line was actually billed in: an alternate
                         unit (e.g. Box) when one was used, otherwise the item's
                         own base unit. Printing the base label on an alternate
                         unit line made qty x rate look wrong on the page. --}}
                    <td>{{ $line->itemUnit?->name ?? $line->item->unit }}</td>
                    {{-- Quantities and rates print at their stored precision
                         (up to 4 decimals) so qty x rate visibly equals the
                         line amount; both used to be cut to 2 decimals. --}}
                    <td class="text-right">{{ Quantity::of($line->quantity)->formatQuantity() }}</td>
                    @if($anyBonus)
                        {{-- Free units are handed over, not sold: the Rate and
                             Amount columns stay the paid quantity's alone. --}}
                        <td class="text-right">{{ Quantity::of($line->bonus_quantity ?? '0')->formatQuantity() }}</td>
                    @endif
                    <td class="text-right">{{ Quantity::of($line->rate)->formatRate() }}</td>
                    <td class="text-right">
                        @if($line->discount_type === 'percentage' && Money::of($line->discount)->isPositive())
                            {{ Money::of($line->discount)->toString() }}% (Rs {{ Money::of($line->discount_amount)->format() }})
                        @else
                            {{ Money::of($line->discount_amount)->format() }}
                        @endif
                    </td>
                    <td class="text-right">{{ Money::of($line->line_total)->format() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Subtotal, Discount, Taxable, Non-taxable, VAT, Grand Total - in that
         order, so the discount is visibly taken off before the taxable amount
         the VAT is charged on. --}}
    <table class="totals-table">
        <tr>
            <td>Subtotal</td>
            <td class="text-right">{{ $subtotal->format() }}</td>
        </tr>
        @if($headerDiscount->isPositive())
            <tr>
                <td>
                    Discount
                    @if($sale->discount_type === 'percentage')
                        ({{ Money::of($sale->discount)->toString() }}%)
                    @endif
                </td>
                <td class="text-right">-{{ $headerDiscount->format() }}</td>
            </tr>
        @endif
        @unless($hidesVatBreakdown)
            <tr>
                <td>Taxable Amount</td>
                <td class="text-right">{{ $taxable->format() }}</td>
            </tr>
            @if($nontaxable->isPositive())
                <tr>
                    <td>Non-taxable Amount</td>
                    <td class="text-right">{{ $nontaxable->format() }}</td>
                </tr>
            @endif
            <tr>
                <td>VAT ({{ Money::of($sale->vat_rate)->toString() }}%)</td>
                <td class="text-right">{{ $vat->format() }}</td>
            </tr>
        @endunless
        <tr class="grand-total">
            <td>Grand Total</td>
            <td class="text-right">{{ $total->format() }}</td>
        </tr>
        {{-- TDS sits below the grand total: it is withheld by the buyer, so it
             never changes the value of the invoice, only what is collected
             against it. The old bill showed TDS but left the grand total
             unreduced with nothing saying what was actually receivable. --}}
        @if($tds->isPositive())
            <tr>
                <td>TDS Withheld</td>
                <td class="text-right">-{{ $tds->format() }}</td>
            </tr>
            <tr class="grand-total">
                <td>Net Receivable</td>
                <td class="text-right">{{ $netReceivable->format() }}</td>
            </tr>
        @endif
    </table>
    <div class="clearfix"></div>

    @if($isAbbreviated)
        <div class="legal-note">
            <strong>Note:</strong> This invoice shall not be issued for the sale of goods or services where the taxable value exceeds NPR 10,000.
        </div>
    @endif

    @if($sale->narration)
        <div class="narration"><strong>Narration:</strong> {{ $sale->narration }}</div>
    @endif
@endsection
