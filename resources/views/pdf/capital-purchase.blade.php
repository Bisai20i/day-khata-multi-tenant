@extends('pdf.layout')

@php
    use App\Support\Money\Money;
    $typeLabel = $capitalPurchase->type === 'service' ? 'Service Purchase' : 'Capital Purchase';
@endphp

@section('title', $typeLabel)

@section('doc-meta-extra')
    @if($capitalPurchase->bill_number)
        <div><strong>Supplier Bill No:</strong> {{ $capitalPurchase->bill_number }}</div>
    @endif
    @if($capitalPurchase->journalVoucher)
        <div><strong>Voucher:</strong> #{{ $capitalPurchase->journalVoucher->voucher_number }}</div>
    @endif
    <div><strong>Payment:</strong> {{ $paymentModeLabel }}</div>
    @if($capitalPurchase->status === 'cancelled')
        <div style="margin-top: 4px;"><span class="status-badge">Cancelled</span></div>
    @endif
@endsection

@section('content')
    <table class="party-table">
        <tr>
            <td>
                <div class="party-label">Supplier</div>
                <div class="party-name">{{ $capitalPurchase->supplier?->name ?? 'No supplier recorded' }}</div>
                @php
                    $supplierPan = $capitalPurchase->supplier_pan ?? $capitalPurchase->supplier?->tpin;
                @endphp
                @if($capitalPurchase->supplier?->address)
                    <div>{{ $capitalPurchase->supplier->address }}</div>
                @endif
                @if($supplierPan)
                    <div>PAN/VAT: {{ $supplierPan }}</div>
                @endif
            </td>
            <td class="text-right">
                @if(in_array($capitalPurchase->payment_mode, ['bank', 'partial'], true) && $capitalPurchase->bankAccount)
                    <div>Bank Account: {{ $capitalPurchase->bankAccount->name }}</div>
                @endif
                @if($capitalPurchase->payment_mode === 'partial')
                    <div>Cash: {{ Money::of($capitalPurchase->cash_amount ?? 0)->format() }}</div>
                    <div>Bank: {{ Money::of($capitalPurchase->bank_amount ?? 0)->format() }}</div>
                @endif
            </td>
        </tr>
    </table>

    @if($capitalPurchase->status === 'cancelled')
        {{-- Who cancelled it, when and why, on the face of the bill (flags G-11). --}}
        <div class="narration" style="border: 1px solid #c0392b; padding: 6px; margin-bottom: 10px;">
            <strong>Cancelled</strong>
            @if($capitalPurchase->cancelled_at)
                on {{ $capitalPurchase->cancelled_at->toDateString() }}
            @endif
            @if($capitalPurchase->canceller)
                by {{ $capitalPurchase->canceller->name }}
            @endif
            @if($capitalPurchase->cancel_reason)
                <div><strong>Reason:</strong> {{ $capitalPurchase->cancel_reason }}</div>
            @endif
        </div>
    @endif

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">S.N.</th>
                <th>Account</th>
                <th>Narration</th>
                <th class="text-center" style="width: 12%;">VAT</th>
                <th class="text-right" style="width: 18%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($capitalPurchase->lines as $index => $line)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $line->account?->code ? $line->account->code.' - ' : '' }}{{ $line->account?->name }}</td>
                    <td>{{ $line->narration ?? '' }}</td>
                    <td class="text-center">{{ $line->vatable ? 'Taxable' : 'Exempt' }}</td>
                    <td class="text-right">{{ Money::of($line->amount)->format() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td>Taxable Amount</td>
            <td class="text-right">{{ $taxable->format() }}</td>
        </tr>
        @if($nontaxable->isPositive())
            <tr>
                <td>Non-taxable Amount</td>
                <td class="text-right">{{ $nontaxable->format() }}</td>
            </tr>
        @endif
        <tr>
            <td>VAT ({{ $capitalPurchase->vat_rate }}%)</td>
            <td class="text-right">{{ $vat->format() }}</td>
        </tr>
        <tr class="grand-total">
            <td>Grand Total</td>
            <td class="text-right">{{ $total->format() }}</td>
        </tr>
        @if($capitalPurchase->payment_mode === 'credit')
            <tr>
                <td>Outstanding</td>
                <td class="text-right">{{ $outstanding->format() }}</td>
            </tr>
        @endif
    </table>
    <div class="clearfix"></div>

    @if($settlements->isNotEmpty())
        <div class="party-label" style="margin-top: 12px;">Payments made against this bill</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 18%;">Date</th>
                    <th>Paid by</th>
                    <th style="width: 16%;">Status</th>
                    <th class="text-right" style="width: 18%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($settlements as $settlement)
                    <tr>
                        <td>{{ $settlement->date->toDateString() }}</td>
                        <td>{{ ucfirst($settlement->payment_mode) }}{{ $settlement->bankAccount ? ' - '.$settlement->bankAccount->name : '' }}</td>
                        <td>{{ $settlement->status === 'cancelled' ? 'Cancelled' : 'Paid' }}</td>
                        <td class="text-right">{{ Money::of($settlement->amount)->format() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($capitalPurchase->narration)
        <div class="narration"><strong>Narration:</strong> {{ $capitalPurchase->narration }}</div>
    @endif
@endsection
