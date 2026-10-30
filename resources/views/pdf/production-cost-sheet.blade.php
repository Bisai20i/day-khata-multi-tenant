@extends('pdf.layout')

@section('title', 'Production Cost Sheet')

@section('doc-meta-extra')
    <div><strong>Finished item:</strong> {{ $entry->finishedItem?->name ?? '-' }}</div>
    @if($entry->store)
        <div><strong>Store:</strong> {{ $entry->store->name }}</div>
    @endif
    <div><strong>Status:</strong> {{ ucfirst(str_replace('_', ' ', $entry->status)) }}</div>
    @if($entry->status === 'cancelled')
        <div style="margin-top: 4px;"><span class="status-badge">Cancelled</span></div>
    @endif
@endsection

@section('content')
    @php
        // Exact decimal formatting from the model's strings, never a float
        // cast (audit P0-1/P0-5). See App\Support\Money.
        $qty = fn ($value) => \App\Support\Money\Quantity::of($value)->formatQuantity();
        $rate = fn ($value) => \App\Support\Money\Quantity::of($value)->formatRate();
        $money = fn ($value) => \App\Support\Money\Money::of($value)->format();
    @endphp

    @if($entry->narration)
        <div class="narration" style="margin-top: 0; margin-bottom: 12px;"><strong>Narration:</strong> {{ $entry->narration }}</div>
    @endif

    <div style="margin-bottom: 10px;">
        <strong>Planned quantity:</strong> {{ $qty($entry->planned_quantity) }}
        @if($entry->finishedItem?->unit)
            {{ $entry->finishedItem->unit }}
        @endif
    </div>

    <div class="party-label" style="margin-bottom: 5px;">Raw materials used</div>
    <table class="items-table" style="margin-bottom: 16px;">
        <thead>
            <tr>
                <th style="width: 5%;">S.N.</th>
                <th>Item</th>
                <th class="text-right" style="width: 14%;">Issued</th>
                <th class="text-right" style="width: 14%;">Returned</th>
                <th class="text-right" style="width: 14%;">Net qty</th>
                <th class="text-right" style="width: 16%;">Net cost</th>
            </tr>
        </thead>
        <tbody>
            @forelse($sheet['materials'] as $index => $line)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>
                        {{ $line['item_name'] }}
                        @if($line['unit'])
                            <span style="color: #888;">({{ $line['unit'] }})</span>
                        @endif
                    </td>
                    <td class="text-right">{{ $qty($line['issued_quantity']) }}</td>
                    <td class="text-right">{{ $qty($line['returned_quantity']) }}</td>
                    <td class="text-right">{{ $qty($line['net_quantity']) }}</td>
                    <td class="text-right">{{ $money($line['net_cost']) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center">No material issued</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="party-label" style="margin-bottom: 5px;">Direct costs</div>
    <table class="items-table" style="margin-bottom: 16px;">
        <thead>
            <tr>
                <th style="width: 5%;">S.N.</th>
                <th>Type</th>
                <th class="text-right" style="width: 20%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse($sheet['direct_costs'] as $index => $cost)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $cost['label'] }}</td>
                    <td class="text-right">{{ $money($cost['amount']) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-center">No direct costs</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($entry->completions->isNotEmpty())
        <div class="party-label" style="margin-bottom: 5px;">Completions</div>
        <table class="items-table" style="margin-bottom: 16px;">
            <thead>
                <tr>
                    <th style="width: 5%;">S.N.</th>
                    <th>Date</th>
                    <th class="text-right" style="width: 14%;">Qty</th>
                    <th class="text-right" style="width: 16%;">Cost</th>
                    <th class="text-right" style="width: 16%;">Unit cost</th>
                    <th style="width: 12%;">Type</th>
                </tr>
            </thead>
            <tbody>
                @foreach($entry->completions->whereNull('cancelled_at')->values() as $index => $completion)
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td>{{ $completion->date->format('Y-m-d') }}</td>
                        <td class="text-right">{{ $qty($completion->quantity) }}</td>
                        <td class="text-right">{{ $money($completion->cost) }}</td>
                        <td class="text-right">{{ $rate($completion->unit_cost) }}</td>
                        <td>{{ $completion->is_final ? 'Final' : 'Partial' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals-table">
        <tr>
            <td>Completed quantity</td>
            <td class="text-right">{{ $qty($sheet['completed_quantity']) }}</td>
        </tr>
        <tr>
            <td>Cost per unit</td>
            <td class="text-right">{{ $rate($sheet['cost_per_unit']) }}</td>
        </tr>
        <tr>
            <td>Remaining WIP</td>
            <td class="text-right">{{ $money($sheet['wip_balance']) }}</td>
        </tr>
        <tr class="grand-total">
            <td>Total Production Cost</td>
            <td class="text-right">{{ $money($sheet['total_production_cost']) }}</td>
        </tr>
    </table>
    <div class="clearfix"></div>

    @if(in_array($entry->status, ['cancelled', 'abandoned'], true) && $entry->cancel_reason)
        <div class="narration"><strong>Reason:</strong> {{ $entry->cancel_reason }}</div>
    @endif
@endsection
