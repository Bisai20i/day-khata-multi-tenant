@extends('pdf.layout')

@section('title', 'Purchase Bill')

@section('doc-meta-extra')
    <div><strong>Payment:</strong> {{ ucfirst($purchase->payment_mode) }}</div>
    @if($purchase->bill_number)
        <div>Supplier Bill #: {{ $purchase->bill_number }}</div>
    @endif
    @if($purchase->status === 'cancelled')
        <div style="margin-top: 4px;"><span class="status-badge">Cancelled</span></div>
    @endif
@endsection

@section('content')
    <table class="party-table">
        <tr>
            <td>
                <div class="party-label">Supplier</div>
                <div class="party-name">{{ $purchase->supplier->name }}</div>
                @if($purchase->supplier->address)
                    <div>{{ $purchase->supplier->address }}</div>
                @endif
                @if($purchase->supplier->mobile_no)
                    <div>Mobile: {{ $purchase->supplier->mobile_no }}</div>
                @endif
                @if($purchase->supplier->tpin)
                    <div>Supplier PAN/VAT: {{ $purchase->supplier->tpin }}</div>
                @endif
                @if($purchase->pan_number)
                    <div>Purchase PAN/VAT: {{ $purchase->pan_number }}</div>
                @endif
            </td>
            <td class="text-right">
                @if($purchase->payment_mode === 'bank' && $purchase->bankAccount)
                    <div>Bank Account: {{ $purchase->bankAccount->name }}</div>
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
            @foreach($purchase->lines as $index => $line)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>
                        {{ $line->item->name }}
                        @if($line->item->unit)
                            <span style="color: #888;">({{ $line->item->unit }})</span>
                        @endif
                    </td>
                    <td class="text-right">{{ number_format((float) $line->quantity, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $line->rate, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $line->discount, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $line->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td>Taxable Amount</td>
            <td class="text-right">{{ number_format((float) $purchase->taxable_amount, 2) }}</td>
        </tr>
        @if((float) $purchase->nontaxable_amount > 0)
            <tr>
                <td>Non-taxable Amount</td>
                <td class="text-right">{{ number_format((float) $purchase->nontaxable_amount, 2) }}</td>
            </tr>
        @endif
        @if((float) $purchase->discount > 0)
            <tr>
                <td>Discount</td>
                <td class="text-right">-{{ number_format((float) $purchase->discount, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td>VAT ({{ number_format((float) $purchase->vat_rate, 2) }}%)</td>
            <td class="text-right">{{ number_format((float) $purchase->vat_amount, 2) }}</td>
        </tr>
        @if((float) $purchase->tds_amount > 0)
            <tr>
                <td>TDS Withheld</td>
                <td class="text-right">-{{ number_format((float) $purchase->tds_amount, 2) }}</td>
            </tr>
        @endif
        <tr class="grand-total">
            <td>Grand Total</td>
            <td class="text-right">{{ number_format((float) $purchase->total, 2) }}</td>
        </tr>
    </table>
    <div class="clearfix"></div>

    @if($purchase->narration)
        <div class="narration"><strong>Narration:</strong> {{ $purchase->narration }}</div>
    @endif
@endsection
