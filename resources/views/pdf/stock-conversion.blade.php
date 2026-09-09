@extends('pdf.layout')

@section('title', 'Stock Conversion')

@section('doc-meta-extra')
    <div><strong>Type:</strong> {{ ucfirst($stockConversion->type->value) }}</div>
    @if($stockConversion->store)
        <div><strong>Store:</strong> {{ $stockConversion->store->name }}</div>
    @endif
    @if($stockConversion->status === 'cancelled')
        <div style="margin-top: 4px;"><span class="status-badge">Cancelled</span></div>
    @endif
@endsection

@section('content')
    @if($stockConversion->note)
        <div class="narration" style="margin-top: 0; margin-bottom: 12px;"><strong>Note:</strong> {{ $stockConversion->note }}</div>
    @endif

    <div class="party-label" style="margin-bottom: 5px;">{{ $inputLabel }}</div>
    <table class="items-table" style="margin-bottom: 16px;">
        <thead>
            <tr>
                <th style="width: 5%;">S.N.</th>
                <th>Item</th>
                <th class="text-right" style="width: 12%;">Qty</th>
                <th class="text-right" style="width: 14%;">Unit Cost</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach($inputLines as $index => $line)
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
                    <td>{{ $line->remarks ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="party-label" style="margin-bottom: 5px;">{{ $outputLabel }}</div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">S.N.</th>
                <th>Item</th>
                <th class="text-right" style="width: 12%;">Qty</th>
                <th class="text-right" style="width: 14%;">Unit Cost</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach($outputLines as $index => $line)
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
                    <td>{{ $line->remarks ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        <tr class="grand-total">
            <td>Total Value</td>
            <td class="text-right">{{ number_format((float) $stockConversion->total_value, 2) }}</td>
        </tr>
    </table>
    <div class="clearfix"></div>

    @if($stockConversion->status === 'cancelled' && $stockConversion->cancel_reason)
        <div class="narration"><strong>Cancellation reason:</strong> {{ $stockConversion->cancel_reason }}</div>
    @endif
@endsection
