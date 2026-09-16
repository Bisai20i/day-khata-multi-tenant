@extends('pdf.layout')

@php
    use App\Support\Money\Money;
@endphp

@section('title', $title ?? 'Account Book')

@section('doc-meta-extra')
    @if(($from ?? null) && ($to ?? null))
        <div><strong>Period:</strong> {{ $from }} to {{ $to }}</div>
    @endif
@endsection

@section('content')
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 10%;">Date</th>
                <th style="width: 20%;">Voucher</th>
                <th>Narration</th>
                <th class="text-right" style="width: 14%;">Debit</th>
                <th class="text-right" style="width: 14%;">Credit</th>
                <th class="text-right" style="width: 14%;">Balance</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="5"><strong>Opening Balance</strong></td>
                <td class="text-right"><strong>{{ Money::of($openingBalance)->format() }}</strong></td>
            </tr>
            @foreach($entries as $entry)
                <tr>
                    <td>{{ $entry['date'] }}</td>
                    <td>{{ strtoupper(str_replace('_', ' ', $entry['voucherType'])) }} #{{ $entry['voucherNumber'] }}</td>
                    <td>{{ $entry['narration'] ?? '' }}</td>
                    <td class="text-right">{{ Money::of($entry['debit'])->format() }}</td>
                    <td class="text-right">{{ Money::of($entry['credit'])->format() }}</td>
                    <td class="text-right">{{ Money::of($entry['balance'])->format() }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="5"><strong>Closing Balance</strong></td>
                <td class="text-right"><strong>{{ Money::of($closingBalance)->format() }}</strong></td>
            </tr>
        </tbody>
    </table>
@endsection
