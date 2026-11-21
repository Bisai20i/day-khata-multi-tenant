@extends('pdf.layout')

@php
    use App\Support\Money\Money;
    use App\Support\Money\Quantity;
@endphp

@section('title', $title ?? 'Item Ledger')

@section('doc-meta-extra')
    @if(!empty($branchFilterLabel))
        <div>{{ $branchFilterLabel }}</div>
    @endif
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
                <th style="width: 9%;">Date</th>
                <th style="width: 11%;">Type</th>
                <th style="width: 11%;">Store</th>
                <th>Reference</th>
                <th class="text-right" style="width: 8%;">In</th>
                <th class="text-right" style="width: 8%;">Out</th>
                <th class="text-right" style="width: 8%;">Quantity</th>
                <th class="text-right" style="width: 8%;">Unit Cost</th>
                <th class="text-right" style="width: 9%;">Balance</th>
                <th class="text-right" style="width: 10%;">Value</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="8"><strong>Opening Balance</strong></td>
                <td class="text-right"><strong>{{ Quantity::of($openingBalance)->formatQuantity() }}</strong></td>
                <td class="text-right"><strong>{{ ($openingValue ?? null) === null ? '-' : Money::of($openingValue)->format() }}</strong></td>
            </tr>
            @foreach($entries as $entry)
                @php
                    $qtyIn = Quantity::of($entry['qtyIn'] ?? '0');
                    $qtyOut = Quantity::of($entry['qtyOut'] ?? '0');
                @endphp
                <tr>
                    <td>{{ $entry['date'] }}</td>
                    <td>{{ $entry['type'] }}</td>
                    <td>{{ $entry['storeName'] ?? '-' }}</td>
                    <td>{{ $entry['reference'] }}</td>
                    <td class="text-right">{{ $qtyIn->isZero() ? '-' : $qtyIn->formatQuantity() }}</td>
                    <td class="text-right">{{ $qtyOut->isZero() ? '-' : $qtyOut->formatQuantity() }}</td>
                    <td class="text-right">{{ Quantity::of($entry['quantity'])->formatQuantity() }}</td>
                    <td class="text-right">{{ $entry['unitCostRate'] === null ? '-' : Quantity::of($entry['unitCostRate'])->formatRate() }}</td>
                    <td class="text-right">{{ Quantity::of($entry['balance'])->formatQuantity() }}</td>
                    <td class="text-right">{{ ($entry['value'] ?? null) === null ? '-' : Money::of($entry['value'])->format() }}</td>
                </tr>
            @endforeach
            @if(!empty($totals))
                <tr>
                    <td colspan="4"><strong>Total</strong></td>
                    <td class="text-right"><strong>{{ Quantity::of($totals['in'])->formatQuantity() }}</strong></td>
                    <td class="text-right"><strong>{{ Quantity::of($totals['out'])->formatQuantity() }}</strong></td>
                    <td class="text-right"><strong>{{ Quantity::of($totals['net'])->formatQuantity() }}</strong></td>
                    <td colspan="3"></td>
                </tr>
            @endif
            <tr>
                <td colspan="8"><strong>Closing Balance</strong></td>
                <td class="text-right"><strong>{{ Quantity::of($closingBalance)->formatQuantity() }}</strong></td>
                <td class="text-right"><strong>{{ ($closingValue ?? null) === null ? '-' : Money::of($closingValue)->format() }}</strong></td>
            </tr>
        </tbody>
    </table>
@endsection
