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
                    <td class="text-right">{{ number_format($line['quantity'], 2) }}</td>
                    <td class="text-right">{{ number_format($line['rate'], 2) }}</td>
                    <td class="text-right">{{ number_format($line['discount'], 2) }}</td>
                    <td class="text-right">{{ number_format($line['line_total'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        @if((float) $quotation->discount > 0)
            <tr>
                <td>Subtotal</td>
                <td class="text-right">{{ number_format($lineSum, 2) }}</td>
            </tr>
            <tr>
                <td>Discount</td>
                <td class="text-right">-{{ number_format((float) $quotation->discount, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td>VAT ({{ number_format((float) $quotation->vat_rate, 2) }}%)</td>
            <td class="text-right">{{ number_format($vat, 2) }}</td>
        </tr>
        <tr class="grand-total">
            <td>Grand Total</td>
            <td class="text-right">{{ number_format($total, 2) }}</td>
        </tr>
    </table>
    <div class="clearfix"></div>

    @if($quotation->narration)
        <div class="narration"><strong>Narration:</strong> {{ $quotation->narration }}</div>
    @endif
@endsection
