@extends('pdf.layout')

@php
    use App\Support\Money\Money;
@endphp

@section('title', 'Day Book')

@section('doc-meta-extra')
    @if(!empty($branchFilterLabel))
        <div>{{ $branchFilterLabel }}</div>
    @endif
    @if(!empty($projectLabel))
        <div><strong>Project:</strong> {{ $projectLabel }}</div>
    @endif
    @if(($from ?? null) && ($to ?? null))
        <div><strong>Period:</strong> {{ $from }} to {{ $to }}</div>
    @endif
@endsection

@section('content')
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 8%;">Date</th>
                <th style="width: 13%;">Voucher</th>
                <th style="width: 12%;">Party</th>
                <th>Account</th>
                <th>Narration</th>
                <th class="text-right" style="width: 10%;">Debit</th>
                <th class="text-right" style="width: 10%;">Credit</th>
                <th class="text-right" style="width: 10%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($vouchers as $voucher)
                @foreach($voucher['lines'] as $index => $line)
                    <tr>
                        <td>{{ $index === 0 ? $voucher['date'] : '' }}</td>
                        <td>{{ $index === 0 ? strtoupper(str_replace('_', ' ', $voucher['voucherType'])).' #'.$voucher['voucherNumber'] : '' }}</td>
                        <td>{{ $index === 0 ? ($voucher['party'] ?? '') : '' }}</td>
                        <td>{{ $line['accountCode'] ? "{$line['accountCode']} - {$line['accountName']}" : $line['accountName'] }}</td>
                        <td>{{ $line['narration'] ?? $voucher['narration'] }}</td>
                        <td class="text-right">{{ Money::of($line['debit'])->format() }}</td>
                        <td class="text-right">{{ Money::of($line['credit'])->format() }}</td>
                        <td class="text-right">{{ $index === 0 && isset($voucher['total']) ? Money::of($voucher['total'])->format() : '' }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5"><strong>Total</strong></td>
                <td class="text-right"><strong>{{ Money::of($totalDebit)->format() }}</strong></td>
                <td class="text-right"><strong>{{ Money::of($totalCredit)->format() }}</strong></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
@endsection
