<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Receipt {{ $documentNumber }}</title>
<style>
    {{--
        Lightweight narrow single-column thermal layout - deliberately does
        not extend pdf.layout (that view's letterhead grid + fixed A4/A5
        @page size doesn't fit a 58mm/80mm roll). SaleController::print()
        sets the actual page width via Pdf::setPaper(), so no @page size
        rule is declared here.
    --}}
    * {
        box-sizing: border-box;
    }

    body {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 9px;
        color: #1a1a1a;
        margin: 0;
        padding: 6px 8px;
    }

    .center {
        text-align: center;
    }

    .company-name {
        font-size: 11px;
        font-weight: bold;
    }

    .meta {
        font-size: 8px;
        color: #333;
        line-height: 1.5;
    }

    .divider {
        border-top: 1px dashed #1a1a1a;
        margin: 6px 0;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    .items-table th,
    .items-table td {
        text-align: left;
        padding: 2px 0;
        font-size: 8.5px;
    }

    .items-table th {
        border-bottom: 1px solid #1a1a1a;
    }

    .text-right {
        text-align: right;
    }

    .totals-table td {
        padding: 2px 0;
        font-size: 9px;
    }

    .totals-table tr.grand-total td {
        border-top: 1px solid #1a1a1a;
        font-weight: bold;
        padding-top: 4px;
    }

    .status-badge {
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: .5px;
    }

    .footer-note {
        margin-top: 10px;
        text-align: center;
        font-size: 8px;
        font-style: italic;
    }
</style>
</head>
<body>
    <div class="center">
        <div class="company-name">{{ $company->company_name }}</div>
        @if($company->address)
            <div class="meta">{{ $company->address }}</div>
        @endif
        @if($company->phone)
            <div class="meta">Phone: {{ $company->phone }}</div>
        @endif
        @if($company->pan_vat_number)
            <div class="meta">PAN/VAT: {{ $company->pan_vat_number }}</div>
        @endif
    </div>

    <div class="divider"></div>

    <div class="meta">
        <div>No: {{ $documentNumber }}</div>
        <div>Date: {{ $documentDate }}</div>
        <div>Customer: {{ $sale->customer->name }}</div>
        <div>Payment: {{ ucfirst($sale->payment_mode) }}</div>
        @if($sale->status === 'cancelled')
            <div class="status-badge">Cancelled</div>
        @endif
    </div>

    <div class="divider"></div>

    <table class="items-table">
        <thead>
            <tr>
                <th>Item</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Rate</th>
                <th class="text-right">Amt</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->lines as $line)
                <tr>
                    <td>{{ $line->item->name }}</td>
                    <td class="text-right">{{ number_format((float) $line->quantity, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $line->rate, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $line->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="divider"></div>

    @php
        // Same paid/due derivation as Pos.vue's receipt snapshot: credit
        // settles nothing up front, partial sums its split cash/bank
        // amounts, cash/bank settle the full amount due (total less any
        // TDS withheld) immediately.
        $paidAmount = match ($sale->payment_mode) {
            'credit' => 0.0,
            'partial' => round((float) $sale->cash_amount + (float) $sale->bank_amount, 2),
            default => round((float) $sale->total - (float) $sale->tds_amount, 2),
        };
        $dueAmount = max(0.0, round((float) $sale->total - (float) $sale->tds_amount - $paidAmount, 2));
    @endphp

    <table class="totals-table">
        <tr class="grand-total">
            <td>Total</td>
            <td class="text-right">{{ number_format((float) $sale->total, 2) }}</td>
        </tr>
        <tr>
            <td>Paid</td>
            <td class="text-right">{{ number_format($paidAmount, 2) }}</td>
        </tr>
        @if($dueAmount > 0)
            <tr>
                <td>Due</td>
                <td class="text-right">{{ number_format($dueAmount, 2) }}</td>
            </tr>
        @endif
    </table>

    @if($company->invoice_footer_note)
        <div class="footer-note">{{ $company->invoice_footer_note }}</div>
    @endif
</body>
</html>
