@extends('pdf.layout')

@section('title', 'Purchase Return / Debit Note')

@section('doc-meta-extra')
    @if($purchaseReturn->purchase_id)
        <div><strong>Against Purchase #:</strong> {{ $purchaseReturn->purchase_id }}</div>
        @if($purchaseReturn->purchase->bill_number)
            <div><strong>Supplier Bill #:</strong> {{ $purchaseReturn->purchase->bill_number }}</div>
        @endif
    @else
        <div><strong>Unlinked return</strong> (no purchase on file - opening stock or before go-live)</div>
    @endif
    @if($purchaseReturn->status === 'cancelled')
        <div style="margin-top: 4px;"><span class="status-badge">Cancelled</span></div>
    @endif
@endsection

@section('content')
    @php($supplier = $purchaseReturn->documentSupplier())
    <table class="party-table">
        <tr>
            <td>
                <div class="party-label">Supplier</div>
                @if($supplier)
                    <div class="party-name">{{ $supplier->name }}</div>
                    @if($supplier->address)
                        <div>{{ $supplier->address }}</div>
                    @endif
                    @if($supplier->mobile_no)
                        <div>Mobile: {{ $supplier->mobile_no }}</div>
                    @endif
                    @if($supplier->tpin)
                        <div>PAN/VAT: {{ $supplier->tpin }}</div>
                    @endif
                @else
                    <div class="party-name">-</div>
                @endif
            </td>
        </tr>
    </table>

    {{--
        Amounts are the stored credited values, not a re-derivation: `line_total`
        is what this return actually credited after the original bill's line and
        header discounts, excluding VAT (CONTRACTS C6). An unlinked line's item
        and unit are read off its own item_id/item_unit_id rather than a
        purchase line (item 4).
    --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">S.N.</th>
                <th>Item</th>
                <th style="width: 10%;">Unit</th>
                <th class="text-right" style="width: 12%;">Qty</th>
                <th class="text-right" style="width: 14%;">Rate</th>
                <th class="text-right" style="width: 16%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($purchaseReturn->lines as $index => $line)
                @php($bonus = \App\Support\Money\Quantity::of($line->bonus_quantity ?? '0'))
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>
                        {{ $line->documentItem()?->name ?? '-' }}
                        {{--
                            Free goods going back (item 3): part of this line's
                            quantity came out of the purchase line's bonus
                            allotment, so it is credited at nothing. Printed so
                            the supplier can see why the amount is lower than
                            quantity x rate.
                        --}}
                        @if($bonus->isPositive())
                            <div style="font-size: 9px;">includes {{ $bonus->formatQuantity() }} free (not credited)</div>
                        @endif
                    </td>
                    <td>{{ $line->documentUnitName() ?? '-' }}</td>
                    <td class="text-right">{{ \App\Support\Money\Quantity::of($line->quantity)->formatQuantity() }}</td>
                    <td class="text-right">{{ \App\Support\Money\Quantity::of($line->rate)->formatRate() }}</td>
                    <td class="text-right">{{ \App\Support\Money\Money::of($line->line_total)->format() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td>Taxable Amount</td>
            <td class="text-right">{{ \App\Support\Money\Money::of($purchaseReturn->taxable_amount)->format() }}</td>
        </tr>
        @if(\App\Support\Money\Money::of($purchaseReturn->nontaxable_amount)->isPositive())
            <tr>
                <td>Non-taxable Amount</td>
                <td class="text-right">{{ \App\Support\Money\Money::of($purchaseReturn->nontaxable_amount)->format() }}</td>
            </tr>
        @endif
        <tr>
            <td>VAT</td>
            <td class="text-right">{{ \App\Support\Money\Money::of($purchaseReturn->vat_amount)->format() }}</td>
        </tr>
        <tr class="grand-total">
            <td>Grand Total</td>
            <td class="text-right">{{ \App\Support\Money\Money::of($purchaseReturn->total)->format() }}</td>
        </tr>
        @if(\App\Support\Money\Money::of($purchaseReturn->tds_amount ?? '0')->isPositive())
            <tr>
                <td>TDS Reversed</td>
                <td class="text-right">-{{ \App\Support\Money\Money::of($purchaseReturn->tds_amount)->format() }}</td>
            </tr>
            <tr>
                <td>Credited to Supplier</td>
                <td class="text-right">{{ $purchaseReturn->supplierCredit()->format() }}</td>
            </tr>
        @endif
    </table>
    <div class="clearfix"></div>

    @if($purchaseReturn->refund_account_id)
        <div class="narration"><strong>Refund received via:</strong> {{ $purchaseReturn->refundAccount->name }}</div>
    @elseif($purchaseReturn->is_unlinked)
        <div class="narration">
            <strong>Refund:</strong>
            @if(\App\Support\Money\Money::of($purchaseReturn->cash_amount ?? '0')->isPositive())
                Cash {{ \App\Support\Money\Money::of($purchaseReturn->cash_amount)->format() }}
            @endif
            @if(\App\Support\Money\Money::of($purchaseReturn->bank_amount ?? '0')->isPositive())
                Bank ({{ $purchaseReturn->bankAccount->name }}) {{ \App\Support\Money\Money::of($purchaseReturn->bank_amount)->format() }}
            @endif
        </div>
    @endif

    @if($purchaseReturn->reason)
        <div class="narration"><strong>Reason:</strong> {{ $purchaseReturn->reason }}</div>
    @endif
@endsection
