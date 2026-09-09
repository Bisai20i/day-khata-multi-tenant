@extends('pdf.layout')

@section('title', 'Stock Adjustment')

@section('doc-meta-extra')
    @if($stockAdjustment->store)
        <div><strong>Store:</strong> {{ $stockAdjustment->store->name }}</div>
    @endif
    @if($stockAdjustment->status === 'cancelled')
        <div style="margin-top: 4px;"><span class="status-badge">Cancelled</span></div>
    @endif
@endsection

@section('content')
    @if($stockAdjustment->note)
        <div class="narration" style="margin-top: 0; margin-bottom: 12px;"><strong>Note:</strong> {{ $stockAdjustment->note }}</div>
    @endif

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">S.N.</th>
                <th>Item</th>
                <th class="text-center" style="width: 8%;">Dir.</th>
                <th style="width: 12%;">Reason</th>
                <th class="text-right" style="width: 10%;">Qty</th>
                <th class="text-right" style="width: 12%;">Unit Cost</th>
                <th class="text-right" style="width: 12%;">Value</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach($stockAdjustment->lines as $index => $line)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>
                        {{ $line->item->name }}
                        @if($line->item->unit)
                            <span style="color: #888;">({{ $line->item->unit }})</span>
                        @endif
                    </td>
                    <td class="text-center">{{ ucfirst($line->direction) }}</td>
                    <td>{{ ucfirst($line->reason_type->value) }}</td>
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
            <td class="text-right">{{ number_format((float) $stockAdjustment->total_value, 2) }}</td>
        </tr>
    </table>
    <div class="clearfix"></div>

    @if($stockAdjustment->status === 'cancelled' && $stockAdjustment->cancel_reason)
        <div class="narration"><strong>Cancellation reason:</strong> {{ $stockAdjustment->cancel_reason }}</div>
    @endif
@endsection
