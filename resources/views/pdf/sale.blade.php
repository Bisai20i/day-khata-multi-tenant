@extends('pdf.layout')

@php
    $isAbbreviated = $sale->invoice_type === 'abbreviated';
    $isPan = $sale->invoice_type === 'pan';
    $hidesVatBreakdown = $isAbbreviated || $isPan;
@endphp

@section('title', $isAbbreviated ? 'Abbreviated Tax Invoice' : ($isPan ? 'PAN Invoice' : 'Tax Invoice'))

@section('doc-meta-extra')
    <div><strong>Payment:</strong> {{ ucfirst($sale->payment_mode) }}</div>
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
                    <div class="party-name">{{ $sale->customer->name }}</div>
                    @if($sale->customer->address)
                        <div>{{ $sale->customer->address }}</div>
                    @endif
                    @if($sale->customer->mobile_no)
                        <div>Mobile: {{ $sale->customer->mobile_no }}</div>
                    @endif
                    @if($sale->customer->tpin)
                        <div>PAN/VAT: {{ $sale->customer->tpin }}</div>
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
                <th class="text-right" style="width: 10%;">Qty</th>
                <th class="text-right" style="width: 12%;">Rate</th>
                <th class="text-right" style="width: 12%;">Discount</th>
                <th class="text-right" style="width: 14%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->lines as $index => $line)
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
                    <td class="text-right">
                        @if($line->discount_type === 'percentage' && (float) $line->discount > 0)
                            {{ number_format((float) $line->discount, 2) }}% (Rs {{ number_format(($line->quantity * $line->rate) - $line->line_total, 2) }})
                        @else
                            {{ number_format((float) $line->discount, 2) }}
                        @endif
                    </td>
                    <td class="text-right">{{ number_format((float) $line->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        @unless($hidesVatBreakdown)
            <tr>
                <td>Taxable Amount</td>
                <td class="text-right">{{ number_format((float) $sale->taxable_amount, 2) }}</td>
            </tr>
            @if((float) $sale->nontaxable_amount > 0)
                <tr>
                    <td>Non-taxable Amount</td>
                    <td class="text-right">{{ number_format((float) $sale->nontaxable_amount, 2) }}</td>
                </tr>
            @endif
        @endunless
        @if((float) $sale->discount > 0)
            <tr>
                <td>
                    Discount
                    @if($sale->discount_type === 'percentage')
                        ({{ number_format((float) $sale->discount, 2) }}%)
                    @endif
                </td>
                <td class="text-right">-{{ number_format($sale->discountAmount(), 2) }}</td>
            </tr>
        @endif
        @unless($hidesVatBreakdown)
            <tr>
                <td>VAT ({{ number_format((float) $sale->vat_rate, 2) }}%)</td>
                <td class="text-right">{{ number_format((float) $sale->vat_amount, 2) }}</td>
            </tr>
            @if((float) $sale->tds_amount > 0)
                <tr>
                    <td>TDS Withheld</td>
                    <td class="text-right">-{{ number_format((float) $sale->tds_amount, 2) }}</td>
                </tr>
            @endif
        @endunless
        <tr class="grand-total">
            <td>Grand Total</td>
            <td class="text-right">{{ number_format((float) $sale->total, 2) }}</td>
        </tr>
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
