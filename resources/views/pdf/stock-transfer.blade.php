@extends('pdf.layout')

@section('title', 'Stock Transfer')

@section('doc-meta-extra')
    @if($stockTransfer->status === 'cancelled')
        <div style="margin-top: 4px;"><span class="status-badge">Cancelled</span></div>
    @endif
@endsection

@section('content')
    @php
        // Exact decimal formatting, never a float cast: a rate of 12.3456
        // used to print as 12.35 and a quantity as a rounded float (audit
        // P0-1/P0-5). See App\Support\Money.
        $qty = fn ($value) => \App\Support\Money\Quantity::of($value)->formatQuantity();
        $rate = fn ($value) => \App\Support\Money\Quantity::of($value)->formatRate();
        $money = fn ($value) => \App\Support\Money\Money::of($value)->format();
    @endphp
    <table class="party-table">
        <tr>
            <td>
                <div class="party-label">From Store</div>
                <div class="party-name">{{ $stockTransfer->fromStore->name ?? '-' }}</div>
            </td>
            <td>
                <div class="party-label">To Store</div>
                <div class="party-name">{{ $stockTransfer->toStore->name ?? '-' }}</div>
            </td>
        </tr>
    </table>

    @if($stockTransfer->note)
        <div class="narration" style="margin-top: 0; margin-bottom: 12px;"><strong>Note:</strong> {{ $stockTransfer->note }}</div>
    @endif

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">S.N.</th>
                <th>Item</th>
                <th class="text-right" style="width: 12%;">Qty</th>
                <th class="text-right" style="width: 14%;">Unit Cost</th>
                <th class="text-right" style="width: 14%;">Value</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach($stockTransfer->lines as $index => $line)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>
                        {{ $line->item->name }}
                        @if($line->item->unit)
                            <span style="color: #888;">({{ $line->item->unit }})</span>
                        @endif
                    </td>
                    <td class="text-right">{{ $qty($line->quantity) }}</td>
                    <td class="text-right">{{ $line->unit_cost_rate !== null ? $rate($line->unit_cost_rate) : '-' }}</td>
                    <td class="text-right">{{ $money($line->line_value) }}</td>
                    <td>{{ $line->remarks ?? '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        <tr class="grand-total">
            <td>Total Value</td>
            <td class="text-right">{{ $money($stockTransfer->total_value) }}</td>
        </tr>
    </table>
    <div class="clearfix"></div>

    @if($stockTransfer->status === 'cancelled' && $stockTransfer->cancel_reason)
        <div class="narration"><strong>Cancellation reason:</strong> {{ $stockTransfer->cancel_reason }}</div>
    @endif
@endsection
