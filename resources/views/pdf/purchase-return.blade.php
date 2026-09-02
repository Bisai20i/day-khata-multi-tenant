@extends('pdf.layout')

@section('title', 'Purchase Return / Debit Note')

@section('doc-meta-extra')
    <div><strong>Against Purchase #:</strong> {{ $purchaseReturn->purchase_id }}</div>
    @if($purchaseReturn->status === 'cancelled')
        <div style="margin-top: 4px;"><span class="status-badge">Cancelled</span></div>
    @endif
@endsection

@section('content')
    <table class="party-table">
        <tr>
            <td>
                <div class="party-label">Supplier</div>
                <div class="party-name">{{ $purchaseReturn->purchase->supplier->name }}</div>
                @if($purchaseReturn->purchase->supplier->address)
                    <div>{{ $purchaseReturn->purchase->supplier->address }}</div>
                @endif
                @if($purchaseReturn->purchase->supplier->mobile_no)
                    <div>Mobile: {{ $purchaseReturn->purchase->supplier->mobile_no }}</div>
                @endif
                @if($purchaseReturn->purchase->supplier->tpin)
                    <div>PAN/VAT: {{ $purchaseReturn->purchase->supplier->tpin }}</div>
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
                <th class="text-right" style="width: 16%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($purchaseReturn->lines as $index => $line)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>
                        {{ $line->purchaseLine->item->name }}
                        @if($line->purchaseLine->item->unit)
                            <span style="color: #888;">({{ $line->purchaseLine->item->unit }})</span>
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
            <td class="text-right">{{ number_format((float) $purchaseReturn->taxable_amount, 2) }}</td>
        </tr>
        @if((float) $purchaseReturn->nontaxable_amount > 0)
            <tr>
                <td>Non-taxable Amount</td>
                <td class="text-right">{{ number_format((float) $purchaseReturn->nontaxable_amount, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td>VAT</td>
            <td class="text-right">{{ number_format((float) $purchaseReturn->vat_amount, 2) }}</td>
        </tr>
        <tr class="grand-total">
            <td>Grand Total</td>
            <td class="text-right">{{ number_format((float) $purchaseReturn->total, 2) }}</td>
        </tr>
    </table>
    <div class="clearfix"></div>

    @if($purchaseReturn->refund_account_id)
        <div class="narration"><strong>Refund received via:</strong> {{ $purchaseReturn->refundAccount->name }}</div>
    @endif

    @if($purchaseReturn->reason)
        <div class="narration"><strong>Reason:</strong> {{ $purchaseReturn->reason }}</div>
    @endif
@endsection
