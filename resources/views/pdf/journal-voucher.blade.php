@extends('pdf.layout')

@php
    use App\Support\Money\Money;
@endphp

@section('title', 'Journal Voucher')

@section('doc-meta-extra')
    @if($voucher->status === 'cancelled')
        <div style="margin-top: 4px;"><span class="status-badge">Cancelled</span></div>
    @endif
@endsection

@section('content')
    <table class="items-table">
        <thead>
            <tr>
                <th>Account</th>
                <th>Narration</th>
                <th class="text-right" style="width: 18%;">Debit</th>
                <th class="text-right" style="width: 18%;">Credit</th>
            </tr>
        </thead>
        <tbody>
            @foreach($voucher->lines as $line)
                <tr>
                    <td>{{ $line->account->code ? "{$line->account->code} - {$line->account->name}" : $line->account->name }}</td>
                    <td>{{ $line->narration ?? $voucher->narration }}</td>
                    <td class="text-right">{{ Money::of($line->debit)->format() }}</td>
                    <td class="text-right">{{ Money::of($line->credit)->format() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($voucher->narration)
        <div class="narration"><strong>Narration:</strong> {{ $voucher->narration }}</div>
    @endif

    <div class="narration">
        <strong>Posted by:</strong> {{ $voucher->creator?->name ?? '—' }}
    </div>
@endsection
