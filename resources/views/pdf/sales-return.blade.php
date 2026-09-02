@extends('pdf.layout')

@section('title', 'Sales Return / Credit Note')

@section('doc-meta-extra')
    <div><strong>Against Sale #:</strong> {{ $salesReturn->sale_id }}</div>
    @if($salesReturn->status === 'cancelled')
        <div style="margin-top: 4px;"><span class="status-badge">Cancelled</span></div>
    @endif
@endsection

@section('content')
    <table class="party-table">
        <tr>
            <td>
                <div class="party-label">Credit To</div>
                <div class="party-name">{{ $salesReturn->sale->customer->name }}</div>
                @if($salesReturn->sale->customer->address)
                    <div>{{ $salesReturn->sale->customer->address }}</div>
                @endif
                @if($salesReturn->sale->customer->mobile_no)
                    <div>Mobile: {{ $salesReturn->sale->customer->mobile_no }}</div>
                @endif
                @if($salesReturn->sale->customer->tpin)
                    <div>PAN/VAT: {{ $salesReturn->sale->customer->tpin }}</div>
                @endif
            </td>
            <td class="text-right">
                @if($salesReturn->refund_account_id && $salesReturn->refundAccount)
                    <div>Refunded via: {{ $salesReturn->refundAccount->name }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">S.N.</th>
                <th>Item</th>
                <th class="text-right" style="width: 12%;">Qty</th>
                <th class="text-right" style="width: 14%;">Rate</th>
                <th class="text-right" style="width: 14%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($salesReturn->lines as $index => $line)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>
                        {{ $line->saleLine->item->name }}
                        @if($line->saleLine->item->unit)
                            <span style="color: #888;">({{ $line->saleLine->item->unit }})</span>
                        @endif
                    </td>
                    <td class="text-right">{{ number_format((float) $line->quantity, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $line->rate, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $line->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td>Taxable Amount</td>
            <td class="text-right">{{ number_format((float) $salesReturn->taxable_amount, 2) }}</td>
        </tr>
        @if((float) $salesReturn->nontaxable_amount > 0)
            <tr>
                <td>Non-taxable Amount</td>
                <td class="text-right">{{ number_format((float) $salesReturn->nontaxable_amount, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td>VAT</td>
            <td class="text-right">{{ number_format((float) $salesReturn->vat_amount, 2) }}</td>
        </tr>
        <tr class="grand-total">
            <td>Grand Total</td>
            <td class="text-right">{{ number_format((float) $salesReturn->total, 2) }}</td>
        </tr>
    </table>
    <div class="clearfix"></div>

    @if($salesReturn->reason)
        <div class="narration"><strong>Reason:</strong> {{ $salesReturn->reason }}</div>
    @endif
@endsection
