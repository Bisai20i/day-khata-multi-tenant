@extends('pdf.layout')

@php
    use App\Support\Money\Money;
@endphp

@section('title', 'Money Receipt')

@section('doc-meta-extra')
    <div><strong>Received as:</strong> {{ ucfirst($receipt->payment_mode) }}</div>
    @if($receipt->reference_number)
        <div><strong>Reference:</strong> {{ $receipt->reference_number }}</div>
    @endif
    @if($receipt->status === 'cancelled')
        <div style="margin-top: 4px;"><span class="status-badge">Cancelled</span></div>
    @endif
@endsection

@section('content')
    <table class="party-table">
        <tr>
            <td>
                <div class="party-label">Received From</div>
                <div class="party-name">{{ $receipt->customer?->name }}</div>
                @if($receipt->customer?->address)
                    <div>{{ $receipt->customer->address }}</div>
                @endif
                @if($receipt->customer?->tpin)
                    <div>PAN/VAT: {{ $receipt->customer->tpin }}</div>
                @endif
            </td>
            <td class="text-right">
                @if($receipt->payment_mode === 'bank' && $receipt->bankAccount)
                    <div>Bank Account: {{ $receipt->bankAccount->name }}</div>
                @endif
            </td>
        </tr>
    </table>

    @if($receipt->status === 'cancelled')
        <div class="narration" style="border: 1px solid #c0392b; padding: 6px; margin-bottom: 10px;">
            <strong>Cancelled</strong>
            @if($receipt->cancelled_at)
                on {{ $receipt->cancelled_at->toDateString() }}
            @endif
            @if($receipt->canceller)
                by {{ $receipt->canceller->name }}
            @endif
            @if($receipt->cancel_reason)
                <div><strong>Reason:</strong> {{ $receipt->cancel_reason }}</div>
            @endif
        </div>
    @endif

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">S.N.</th>
                <th>Settles invoice</th>
                <th style="width: 18%;">Invoice date</th>
                <th class="text-right" style="width: 18%;">Invoice total</th>
                <th class="text-right" style="width: 18%;">Amount applied</th>
            </tr>
        </thead>
        <tbody>
            @forelse($receipt->allocations as $index => $allocation)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $allocation->sale?->invoice_number ?? '#'.$allocation->sale_id }}</td>
                    <td>{{ $allocation->sale?->date?->toDateString() }}</td>
                    <td class="text-right">{{ $allocation->sale ? Money::of($allocation->sale->total)->format() : '' }}</td>
                    <td class="text-right">{{ Money::of($allocation->amount)->format() }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">Received on account, not applied to a specific invoice.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals-table">
        @if($receipt->allocations->isNotEmpty() && ! $allocated->isEqualTo($amount))
            <tr>
                <td>Applied to invoices</td>
                <td class="text-right">{{ $allocated->format() }}</td>
            </tr>
            <tr>
                <td>On account</td>
                <td class="text-right">{{ $amount->minus($allocated)->format() }}</td>
            </tr>
        @endif
        <tr class="grand-total">
            <td>Amount Received</td>
            <td class="text-right">{{ $amount->format() }}</td>
        </tr>
    </table>
    <div class="clearfix"></div>

    @if($receipt->narration)
        <div class="narration"><strong>Narration:</strong> {{ $receipt->narration }}</div>
    @endif
@endsection
