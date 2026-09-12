@extends('pdf.layout')

@section('title', 'Quotation')

@section('doc-meta-extra')
    @if($quotation->status->value === 'converted')
        <div style="margin-top: 4px;"><span class="status-badge">Converted</span></div>
        <div style="margin-top: 4px;">Converted to Sale #: {{ $quotation->sale_id }}</div>
    @elseif($quotation->status->value === 'cancelled')
        <div style="margin-top: 4px;"><span class="status-badge">Cancelled</span></div>
    @endif
@endsection

@section('content')
    {{-- Every amount below is a Money or Quantity value object formatted by
         itself (Indian grouping for money, trimmed decimals for quantities).
         The old view ran number_format() over raw floats and applied VAT to
         the whole discounted line total regardless of whether the item was
         vatable, which is why a printed quote never matched the sale it
         became (audit P0-1, P0-9). --}}
    <table class="party-table">
        <tr>
            <td>
                <div class="party-label">Quotation For</div>
                <div class="party-name">{{ $quotation->customer->name }}</div>
                @if($quotation->customer->address)
                    <div>{{ $quotation->customer->address }}</div>
                @endif
                @if($quotation->customer->mobile_no)
                    <div>Mobile: {{ $quotation->customer->mobile_no }}</div>
                @endif
                @if($quotation->customer->tpin)
                    <div>PAN/VAT: {{ $quotation->customer->tpin }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">S.N.</th>
                <th>Item</th>
                <th class="text-right" style="width: 10%;">Qty</th>
                <th class="text-right" style="width: 12%;">Rate</th>
                <th class="text-right" style="width: 12%;">Discount</th>
                <th class="text-right" style="width: 14%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lines as $index => $line)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>
                        {{ $line['item']->name }}
                        @if($line['item']->unit)
                            <span style="color: #888;">({{ $line['item']->unit }})</span>
                        @endif
                    </td>
                    <td class="text-right">{{ $line['quantity']->formatQuantity() }}</td>
                    <td class="text-right">{{ $line['rate']->formatRate() }}</td>
                    <td class="text-right">{{ $line['discount']->format() }}</td>
                    <td class="text-right">{{ $line['line_total']->format() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td>Subtotal</td>
            <td class="text-right">{{ $subtotal->format() }}</td>
        </tr>
        @if(! $subtotal->minus($taxable)->minus($nontaxable)->isZero())
            <tr>
                <td>Discount</td>
                <td class="text-right">-{{ $subtotal->minus($taxable)->minus($nontaxable)->format() }}</td>
            </tr>
        @endif
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
            <td>VAT ({{ $quotation->vat_rate }}%)</td>
            <td class="text-right">{{ $vat->format() }}</td>
        </tr>
        <tr class="grand-total">
            <td>Grand Total</td>
            <td class="text-right">{{ $total->format() }}</td>
        </tr>
    </table>
    <div class="clearfix"></div>

    @if($quotation->narration)
        <div class="narration"><strong>Narration:</strong> {{ $quotation->narration }}</div>
    @endif
@endsection
