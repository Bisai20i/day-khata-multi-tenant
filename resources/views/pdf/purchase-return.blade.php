@extends('pdf.layout')

@section('title', 'Purchase Return / Debit Note')

@section('doc-meta-extra')
    <div><strong>Against Purchase #:</strong> {{ $purchaseReturn->purchase_id }}</div>
    @if($purchaseReturn->purchase->bill_number)
        <div><strong>Supplier Bill #:</strong> {{ $purchaseReturn->purchase->bill_number }}</div>
    @endif
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

    {{--
        Amounts are the stored credited values, not a re-derivation: `line_total`
        is what this return actually credited after the original bill's line and
        header discounts, excluding VAT (CONTRACTS C6).
    --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">S.N.</th>
                <th>Item</th>
                <th style="width: 10%;">Unit</th>
                <th class="text-right" style="width: 12%;">Qty</th>
                <th class="text-right" style="width: 14%;">Rate</th>
                <th class="text-right" style="width: 16%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($purchaseReturn->lines as $index => $line)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $line->purchaseLine->item->name }}</td>
                    <td>{{ $line->purchaseLine->unitName() ?? '-' }}</td>
                    <td class="text-right">{{ \App\Support\Money\Quantity::of($line->quantity)->formatQuantity() }}</td>
                    <td class="text-right">{{ \App\Support\Money\Quantity::of($line->rate)->formatRate() }}</td>
                    <td class="text-right">{{ \App\Support\Money\Money::of($line->line_total)->format() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td>Taxable Amount</td>
            <td class="text-right">{{ \App\Support\Money\Money::of($purchaseReturn->taxable_amount)->format() }}</td>
        </tr>
        @if(\App\Support\Money\Money::of($purchaseReturn->nontaxable_amount)->isPositive())
            <tr>
                <td>Non-taxable Amount</td>
                <td class="text-right">{{ \App\Support\Money\Money::of($purchaseReturn->nontaxable_amount)->format() }}</td>
            </tr>
        @endif
        <tr>
            <td>VAT</td>
            <td class="text-right">{{ \App\Support\Money\Money::of($purchaseReturn->vat_amount)->format() }}</td>
        </tr>
        <tr class="grand-total">
            <td>Grand Total</td>
            <td class="text-right">{{ \App\Support\Money\Money::of($purchaseReturn->total)->format() }}</td>
        </tr>
        @if(\App\Support\Money\Money::of($purchaseReturn->tds_amount ?? '0')->isPositive())
            <tr>
                <td>TDS Reversed</td>
                <td class="text-right">-{{ \App\Support\Money\Money::of($purchaseReturn->tds_amount)->format() }}</td>
            </tr>
            <tr>
                <td>Credited to Supplier</td>
                <td class="text-right">{{ $purchaseReturn->supplierCredit()->format() }}</td>
            </tr>
        @endif
    </table>
    <div class="clearfix"></div>

    @if($purchaseReturn->refund_account_id)
        <div class="narration"><strong>Refund received via:</strong> {{ $purchaseReturn->refundAccount->name }}</div>
    @endif

    @if($purchaseReturn->reason)
        <div class="narration"><strong>Reason:</strong> {{ $purchaseReturn->reason }}</div>
    @endif
@endsection
