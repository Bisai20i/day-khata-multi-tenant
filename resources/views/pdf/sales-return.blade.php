@extends('pdf.layout')

{{-- Only a return that actually posted is a credit note (CONTRACTS C7): a
     pending or rejected request must never be titled or numbered as one, so
     it prints as the request it is. --}}
@section('title', $isCreditNote ? 'Credit Note' : 'Return Request')

@section('doc-meta-extra')
    {{-- An unlinked return (C7 "returns without a bill") has no parent sale
         at all: it names no invoice, and every party/item detail below comes
         off the return itself instead of off the sale. --}}
    @if($salesReturn->sale)
        <div><strong>Against Invoice:</strong> {{ $salesReturn->sale->invoice_number ?? '#'.$salesReturn->sale_id }}</div>
        <div><strong>Invoice Date:</strong> {{ $salesReturn->sale->date->format('Y-m-d') }}</div>
    @else
        <div><strong>Against Invoice:</strong> No original bill</div>
    @endif
    @if($salesReturn->status === 'cancelled')
        <div style="margin-top: 4px;"><span class="status-badge">Cancelled</span></div>
    @elseif(! $isCreditNote)
        <div style="margin-top: 4px;"><span class="status-badge">{{ $salesReturn->status === 'rejected' ? 'Rejected' : 'Awaiting approval' }}</span></div>
    @endif
@endsection

@section('content')
    @php
        // Linked: the buyer snapshot frozen on the invoice (C7), falling back
        // to the live customer for rows issued before those columns existed.
        // Unlinked: the return's own customer, the only party it has.
        $customer = $salesReturn->sale?->customer ?? $salesReturn->customer;
        $creditToName = $salesReturn->sale?->buyer_name ?? $customer?->name;
        $creditToAddress = $salesReturn->sale?->buyer_address ?? $customer?->address;
        $creditToPan = $salesReturn->sale?->buyer_pan ?? $customer?->tpin;
        $refundCash = \App\Support\Money\Money::ofNullable($salesReturn->refund_cash_amount);
        $refundBank = \App\Support\Money\Money::ofNullable($salesReturn->refund_bank_amount);
    @endphp

    <table class="party-table">
        <tr>
            <td>
                <div class="party-label">Credit To</div>
                <div class="party-name">{{ $creditToName }}</div>
                @if($creditToAddress)
                    <div>{{ $creditToAddress }}</div>
                @endif
                @if($customer?->mobile_no)
                    <div>Mobile: {{ $customer->mobile_no }}</div>
                @endif
                @if($creditToPan)
                    <div>PAN/VAT: {{ $creditToPan }}</div>
                @endif
            </td>
            <td class="text-right">
                {{-- The refund split (audit section 4 polish): both legs are
                     printed when the money went out through two accounts, so
                     the note says exactly where each rupee came from. --}}
                @if($refundCash && $refundCash->isPositive())
                    <div>Refunded in cash: {{ $refundCash->format() }}</div>
                @endif
                @if($refundBank && $refundBank->isPositive() && $salesReturn->refundAccount)
                    <div>Refunded via {{ $salesReturn->refundAccount->name }}: {{ $refundBank->format() }}</div>
                @elseif(! $refundCash && ! $refundBank && $salesReturn->refund_account_id && $salesReturn->refundAccount)
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
                        @php($lineItem = $line->saleLine?->item ?? $line->item)
                        {{ $lineItem?->name }}
                        @php($unit = ($line->saleLine?->itemUnit ?? $line->itemUnit)?->name ?? $lineItem?->unit)
                        @if($unit)
                            <span style="color: #888;">({{ $unit }})</span>
                        @endif
                    </td>
                    <td class="text-right">
                        {{ \App\Support\Money\Quantity::of($line->quantity)->formatQuantity() }}
                        {{-- Bonus units come back at zero value (audit section 3 "Sales"). --}}
                        @php($bonus = \App\Support\Money\Quantity::of($line->bonus_quantity ?? '0'))
                        @if($bonus->isPositive())
                            <span style="color: #888;">+ {{ $bonus->formatQuantity() }} free</span>
                        @endif
                    </td>
                    <td class="text-right">{{ \App\Support\Money\Quantity::of($line->rate)->formatRate() }}</td>
                    <td class="text-right">{{ \App\Support\Money\Money::of($line->line_total)->format() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td>Taxable Amount</td>
            <td class="text-right">{{ \App\Support\Money\Money::of($salesReturn->taxable_amount)->format() }}</td>
        </tr>
        @if(\App\Support\Money\Money::of($salesReturn->nontaxable_amount)->isPositive())
            <tr>
                <td>Non-taxable Amount</td>
                <td class="text-right">{{ \App\Support\Money\Money::of($salesReturn->nontaxable_amount)->format() }}</td>
            </tr>
        @endif
        <tr>
            <td>VAT</td>
            <td class="text-right">{{ \App\Support\Money\Money::of($salesReturn->vat_amount)->format() }}</td>
        </tr>
        <tr class="grand-total">
            <td>Grand Total</td>
            <td class="text-right">{{ \App\Support\Money\Money::of($salesReturn->total)->format() }}</td>
        </tr>
        @if(\App\Support\Money\Money::of($salesReturn->tds_amount)->isPositive())
            <tr>
                <td>TDS reversed</td>
                <td class="text-right">{{ \App\Support\Money\Money::of($salesReturn->tds_amount)->format() }}</td>
            </tr>
            <tr>
                <td>Credited to Account</td>
                <td class="text-right">{{ \App\Support\Money\Money::of($salesReturn->total)->minus(\App\Support\Money\Money::of($salesReturn->tds_amount))->format() }}</td>
            </tr>
        @endif
    </table>
    <div class="clearfix"></div>

    @if($salesReturn->reason)
        <div class="narration"><strong>Reason:</strong> {{ $salesReturn->reason }}</div>
    @endif

    @if($salesReturn->status === 'rejected' && $salesReturn->rejection_reason)
        <div class="narration"><strong>Rejected because:</strong> {{ $salesReturn->rejection_reason }}</div>
    @endif

    @if($salesReturn->status === 'cancelled' && $salesReturn->cancel_reason)
        <div class="narration"><strong>Cancelled because:</strong> {{ $salesReturn->cancel_reason }}</div>
    @endif
@endsection
