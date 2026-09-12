@extends('pdf.layout')

@section('title', 'Purchase Bill')

@section('doc-meta-extra')
    <div><strong>Payment:</strong> {{ ucfirst($purchase->payment_mode) }}</div>
    @if($purchase->bill_number)
        <div><strong>Supplier Bill #:</strong> {{ $purchase->bill_number }}</div>
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

    {{--
        Every figure below is the value stored on the row, formatted by the money
        value objects. Nothing is recomputed here and nothing is cast to float:
        a PDF that does its own arithmetic is how a bill ends up disagreeing with
        the ledger by a paisa (audit P0-1, P0-8).
    --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">S.N.</th>
                <th>Item</th>
                <th style="width: 10%;">Unit</th>
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
                    <td>{{ $line->item->name }}</td>
                    <td>{{ $line->unitName() ?? '-' }}</td>
                    <td class="text-right">{{ \App\Support\Money\Quantity::of($line->quantity)->formatQuantity() }}</td>
                    <td class="text-right">{{ \App\Support\Money\Quantity::of($line->rate)->formatRate() }}</td>
                    <td class="text-right">
                        @if($line->discount_type === 'percentage')
                            {{ \App\Support\Money\Money::of($line->discount)->toString() }}%
                            (Rs {{ $line->discountAmount()->format() }})
                        @else
                            {{ $line->discountAmount()->format() }}
                        @endif
                    </td>
                    <td class="text-right">{{ \App\Support\Money\Money::of($line->line_total)->format() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        @php($headerDiscount = $purchase->discountAmount())
        @if($headerDiscount->isPositive())
            <tr>
                <td>
                    Discount
                    @if($purchase->discount_type === 'percentage')
                        ({{ \App\Support\Money\Money::of($purchase->discount)->toString() }}%)
                    @endif
                </td>
                <td class="text-right">-{{ $headerDiscount->format() }}</td>
            </tr>
        @endif
        <tr>
            <td>Taxable Amount</td>
            <td class="text-right">{{ \App\Support\Money\Money::of($purchase->taxable_amount)->format() }}</td>
        </tr>
        @if(\App\Support\Money\Money::of($purchase->nontaxable_amount)->isPositive())
            <tr>
                <td>Non-taxable Amount</td>
                <td class="text-right">{{ \App\Support\Money\Money::of($purchase->nontaxable_amount)->format() }}</td>
            </tr>
        @endif
        <tr>
            <td>VAT ({{ \App\Support\Money\Money::of($purchase->vat_rate)->toString() }}%)</td>
            <td class="text-right">{{ \App\Support\Money\Money::of($purchase->vat_amount)->format() }}</td>
        </tr>
        <tr class="grand-total">
            <td>Grand Total</td>
            <td class="text-right">{{ \App\Support\Money\Money::of($purchase->total)->format() }}</td>
        </tr>
        @if(\App\Support\Money\Money::of($purchase->tds_amount)->isPositive())
            <tr>
                <td>TDS Withheld</td>
                <td class="text-right">-{{ \App\Support\Money\Money::of($purchase->tds_amount)->format() }}</td>
            </tr>
            <tr>
                <td>Amount Payable</td>
                <td class="text-right">{{ \App\Support\Money\Money::of($purchase->total)->minus(\App\Support\Money\Money::of($purchase->tds_amount))->format() }}</td>
            </tr>
        @endif
    </table>
    <div class="clearfix"></div>

    @if($purchase->narration)
        <div class="narration"><strong>Narration:</strong> {{ $purchase->narration }}</div>
    @endif
@endsection
