@extends('pdf.layout')

@php
    use App\Support\Money\Quantity;
@endphp

@section('title', $title ?? 'Item Ledger')

@section('doc-meta-extra')
    @if(($from ?? null) && ($to ?? null))
        <div><strong>Period:</strong> {{ $from }} to {{ $to }}</div>
    @endif
    @if($item->unit)
        <div><strong>Unit:</strong> {{ $item->unit }}</div>
    @endif
    <div><strong>Store:</strong> {{ $storeName ?? 'All stores' }}</div>
@endsection

@section('content')
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 10%;">Date</th>
                <th style="width: 14%;">Type</th>
                <th style="width: 14%;">Store</th>
                <th>Reference</th>
                <th class="text-right" style="width: 12%;">Quantity</th>
                <th class="text-right" style="width: 12%;">Unit Cost</th>
                <th class="text-right" style="width: 12%;">Balance</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="6"><strong>Opening Balance</strong></td>
                <td class="text-right"><strong>{{ Quantity::of($openingBalance)->formatQuantity() }}</strong></td>
            </tr>
            @foreach($entries as $entry)
                <tr>
                    <td>{{ $entry['date'] }}</td>
                    <td>{{ $entry['type'] }}</td>
                    <td>{{ $entry['storeName'] ?? '-' }}</td>
                    <td>{{ $entry['reference'] }}</td>
                    <td class="text-right">{{ Quantity::of($entry['quantity'])->formatQuantity() }}</td>
                    <td class="text-right">{{ $entry['unitCostRate'] === null ? '-' : Quantity::of($entry['unitCostRate'])->formatRate() }}</td>
                    <td class="text-right">{{ Quantity::of($entry['balance'])->formatQuantity() }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="6"><strong>Closing Balance</strong></td>
                <td class="text-right"><strong>{{ Quantity::of($closingBalance)->formatQuantity() }}</strong></td>
            </tr>
        </tbody>
    </table>
@endsection
