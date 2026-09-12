@extends('pdf.layout')

@section('title', 'Tax Invoice')

@section('doc-meta-extra')
    <div><strong>Payment:</strong> {{ ucfirst($capitalSale->payment_mode) }}</div>
    @if($capitalSale->status === 'cancelled')
        <div style="margin-top: 4px;"><span class="status-badge">Cancelled</span></div>
    @endif
@endsection

@section('content')
    {{-- The buyer block prints the snapshot taken when the invoice was issued,
         falling back to the live customer only for rows posted before those
         columns existed (C7). A customer who later corrects their PAN must not
         change what an already-issued invoice says. --}}
    <table class="party-table">
        <tr>
            <td>
                <div class="party-label">Bill To</div>
                <div class="party-name">{{ $capitalSale->buyer_name ?? $capitalSale->customer?->name ?? 'Cash Customer' }}</div>
                @php
                    $buyerAddress = $capitalSale->buyer_address ?? $capitalSale->customer?->address;
                    $buyerPan = $capitalSale->buyer_pan ?? $capitalSale->customer?->tpin;
                @endphp
                @if($buyerAddress)
                    <div>{{ $buyerAddress }}</div>
                @endif
                @if($buyerPan)
                    <div>PAN/VAT: {{ $buyerPan }}</div>
                @endif
            </td>
            <td class="text-right">
                @if(in_array($capitalSale->payment_mode, ['bank', 'partial'], true) && $capitalSale->bankAccount)
                    <div>Bank Account: {{ $capitalSale->bankAccount->name }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">S.N.</th>
                <th>Particulars</th>
                <th class="text-center" style="width: 12%;">VAT</th>
                <th class="text-right" style="width: 18%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($capitalSale->lines as $index => $line)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>
                        {{ $line->account?->name }}
                        @if($line->narration)
                            <span style="color: #888;">- {{ $line->narration }}</span>
                        @endif
                    </td>
                    <td class="text-center">{{ $line->vatable ? 'Taxable' : 'Exempt' }}</td>
                    <td class="text-right">{{ \App\Support\Money\Money::of($line->amount)->format() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
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
            <td>VAT ({{ $capitalSale->vat_rate }}%)</td>
            <td class="text-right">{{ $vat->format() }}</td>
        </tr>
        <tr class="grand-total">
            <td>Grand Total</td>
            <td class="text-right">{{ $total->format() }}</td>
        </tr>
    </table>
    <div class="clearfix"></div>

    @if($capitalSale->narration)
        <div class="narration"><strong>Narration:</strong> {{ $capitalSale->narration }}</div>
    @endif
@endsection
