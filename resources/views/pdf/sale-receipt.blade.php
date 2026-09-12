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

    .doc-title {
        margin-top: 2px;
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: .5px;
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

    .copy-stamp {
        margin-top: 2px;
        font-size: 8px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: .5px;
    }

    .words {
        margin-top: 6px;
        font-size: 8px;
        font-style: italic;
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
@php
    use App\Support\Money\Money;
    use App\Support\Money\Quantity;

    $isAbbreviated = $sale->invoice_type === 'abbreviated';
    $isPan = $sale->invoice_type === 'pan';
    $isFullTaxInvoice = ! $isAbbreviated && ! $isPan;

    $total = Money::of($sale->total);
    $tds = Money::of($sale->tds_amount);
    $headerDiscount = Money::of($sale->discount_amount);
    $taxable = Money::of($sale->taxable_amount);
    $nontaxable = Money::of($sale->nontaxable_amount);
    $vat = Money::of($sale->vat_amount);
    $subtotal = $taxable->plus($nontaxable)->plus($headerDiscount);

    // Exactly what the ledger booked: credit settles nothing up front, partial
    // uses its stored split, cash and bank settle the amount due (total less
    // any TDS withheld). Nothing here is recomputed from quantities and rates.
    $settlementDue = $total->minus($tds);
    $paidAmount = match ($sale->payment_mode) {
        'credit' => Money::zero(),
        'partial' => Money::of($sale->cash_amount ?? '0')->plus(Money::of($sale->bank_amount ?? '0')),
        default => $settlementDue,
    };
    $dueAmount = $settlementDue->minus($paidAmount);

    $buyerName = $sale->buyer_name ?? $sale->customer->name;
    $buyerPan = $sale->buyer_pan ?? $sale->customer->tpin;

    $printCopyNumber = max(1, (int) ($copyNumber ?? 1));
    $printDateBs = $dateBs ?? \App\Support\NepaliCalendar::formatBs($documentDate);
@endphp
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
        {{-- A thermal roll is still a legal document: a full tax invoice has
             to say so on its face, not just be titled "Receipt". --}}
        <div class="doc-title">
            {{ $isAbbreviated ? 'Abbreviated Tax Invoice' : ($isPan ? 'PAN Invoice' : 'Tax Invoice') }}
        </div>
        <div class="copy-stamp">
            {{ $printCopyNumber > 1 ? 'Copy of Original - '.($printCopyNumber - 1) : 'Original' }}
        </div>
    </div>

    <div class="divider"></div>

    <div class="meta">
        <div>No: {{ $documentNumber }}</div>
        @if($printDateBs !== '')
            <div>Date (BS): {{ $printDateBs }} (AD {{ $dateAd ?? $documentDate }})</div>
        @else
            <div>Date (AD): {{ $dateAd ?? $documentDate }}</div>
        @endif
        @if(! empty($fiscalYearName))
            <div>Fiscal Year: {{ $fiscalYearName }}</div>
        @endif
        @unless($isAbbreviated)
            <div>Customer: {{ $buyerName }}</div>
            @if($buyerPan)
                <div>Buyer PAN: {{ $buyerPan }}</div>
            @endif
        @endunless
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
                    <td>{{ $line->item->name }} <span style="color:#666;">({{ $line->itemUnit?->name ?? $line->item->unit }})</span></td>
                    <td class="text-right">{{ Quantity::of($line->quantity)->formatQuantity() }}</td>
                    <td class="text-right">{{ Quantity::of($line->rate)->formatRate() }}</td>
                    <td class="text-right">{{ Money::of($line->line_total)->format() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="divider"></div>

    <table class="totals-table">
        <tr>
            <td>Subtotal</td>
            <td class="text-right">{{ $subtotal->format() }}</td>
        </tr>
        @if($headerDiscount->isPositive())
            <tr>
                <td>Discount</td>
                <td class="text-right">-{{ $headerDiscount->format() }}</td>
            </tr>
        @endif
        {{-- A full tax invoice keeps its VAT breakdown even on thermal paper;
             it used to be dropped entirely, which made the roll unusable as a
             tax invoice. --}}
        @if($isFullTaxInvoice)
            <tr>
                <td>Taxable</td>
                <td class="text-right">{{ $taxable->format() }}</td>
            </tr>
            @if($nontaxable->isPositive())
                <tr>
                    <td>Non-taxable</td>
                    <td class="text-right">{{ $nontaxable->format() }}</td>
                </tr>
            @endif
            <tr>
                <td>VAT ({{ Money::of($sale->vat_rate)->toString() }}%)</td>
                <td class="text-right">{{ $vat->format() }}</td>
            </tr>
        @endif
        <tr class="grand-total">
            <td>Total</td>
            <td class="text-right">{{ $total->format() }}</td>
        </tr>
        @if($tds->isPositive())
            <tr>
                <td>TDS Withheld</td>
                <td class="text-right">-{{ $tds->format() }}</td>
            </tr>
            <tr class="grand-total">
                <td>Net Receivable</td>
                <td class="text-right">{{ $settlementDue->format() }}</td>
            </tr>
        @endif
        <tr>
            <td>Paid</td>
            <td class="text-right">{{ $paidAmount->format() }}</td>
        </tr>
        @if($dueAmount->isPositive())
            <tr>
                <td>Due</td>
                <td class="text-right">{{ $dueAmount->format() }}</td>
            </tr>
        @endif
    </table>

    @if(! empty($amountInWords))
        <div class="words">{{ $amountInWords }}</div>
    @endif

    @if($company->invoice_footer_note)
        <div class="footer-note">{{ $company->invoice_footer_note }}</div>
    @endif
</body>
</html>
