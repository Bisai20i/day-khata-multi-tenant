@extends('pdf.layout')

@section('title', 'Stock Transfer')

@section('doc-meta-extra')
    @if($stockTransfer->status === 'cancelled')
        <div style="margin-top: 4px;"><span class="status-badge">Cancelled</span></div>
    @endif
@endsection

@section('content')
    <table class="party-table">
        <tr>
            <td>
                <div class="party-label">From Store</div>
                <div class="party-name">{{ $stockTransfer->fromStore->name ?? '—' }}</div>
            </td>
            <td>
                <div class="party-label">To Store</div>
                <div class="party-name">{{ $stockTransfer->toStore->name ?? '—' }}</div>
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
                    <td class="text-right">{{ number_format((float) $line->quantity, 4) }}</td>
                    <td class="text-right">{{ $line->unit_cost_rate !== null ? number_format((float) $line->unit_cost_rate, 2) : '—' }}</td>
                    <td class="text-right">{{ number_format((float) $line->line_value, 2) }}</td>
                    <td>{{ $line->remarks ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        <tr class="grand-total">
            <td>Total Value</td>
            <td class="text-right">{{ number_format((float) $stockTransfer->total_value, 2) }}</td>
        </tr>
    </table>
    <div class="clearfix"></div>

    @if($stockTransfer->status === 'cancelled' && $stockTransfer->cancel_reason)
        <div class="narration"><strong>Cancellation reason:</strong> {{ $stockTransfer->cancel_reason }}</div>
    @endif
@endsection
