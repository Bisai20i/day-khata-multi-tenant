@extends('pdf.layout')

@section('title', 'Material Issue Note')

@section('doc-meta-extra')
    @if($note->store)
        <div><strong>Store:</strong> {{ $note->store->name }}</div>
    @endif
    @if($note->productionEntry)
        <div><strong>Production entry:</strong> PE-{{ $note->productionEntry->number }}</div>
    @endif
    @if($note->status === 'cancelled')
        <div style="margin-top: 4px;"><span class="status-badge">Cancelled</span></div>
    @endif
@endsection

@section('content')
    @php
        // Exact decimal formatting, never a float cast (audit P0-1/P0-5).
        $qty = fn ($value) => \App\Support\Money\Quantity::of($value)->formatQuantity();
        $rate = fn ($value) => \App\Support\Money\Quantity::of($value)->formatRate();
        $money = fn ($value) => \App\Support\Money\Money::of($value)->format();
    @endphp
    @if($note->narration)
        <div class="narration" style="margin-top: 0; margin-bottom: 12px;"><strong>Narration:</strong> {{ $note->narration }}</div>
    @endif

    <div class="party-label" style="margin-bottom: 5px;">Raw materials issued to production</div>
    <table class="items-table" style="margin-bottom: 16px;">
        <thead>
            <tr>
                <th style="width: 5%;">S.N.</th>
                <th>Item</th>
                <th class="text-right" style="width: 14%;">Qty</th>
                <th class="text-right" style="width: 14%;">Base Qty</th>
                <th class="text-right" style="width: 14%;">Unit Cost</th>
                <th class="text-right" style="width: 16%;">Cost</th>
            </tr>
        </thead>
        <tbody>
            @foreach($note->items as $index => $line)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>
                        {{ $line->item->name }}
                        @if($line->item->unit)
                            <span style="color: #888;">({{ $line->item->unit }})</span>
                        @endif
                    </td>
                    <td class="text-right">{{ $qty($line->quantity) }} {{ $line->itemUnit?->name }}</td>
                    <td class="text-right">{{ $qty($line->base_quantity) }}</td>
                    <td class="text-right">{{ $rate($line->unit_cost_rate) }}</td>
                    <td class="text-right">{{ $money($line->line_cost) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        <tr class="grand-total">
            <td>Total Cost</td>
            <td class="text-right">{{ $money($note->total_cost) }}</td>
        </tr>
    </table>
    <div class="clearfix"></div>

    @if($note->status === 'cancelled' && $note->cancel_reason)
        <div class="narration"><strong>Cancellation reason:</strong> {{ $note->cancel_reason }}</div>
    @endif
@endsection
