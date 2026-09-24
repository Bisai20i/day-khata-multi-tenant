{{-- PAN INVOICE, ported from legacy bills/pan-invoice-print-preview. A PAN
     seller charges no VAT, so the VAT rows the legacy view kept hidden are
     simply not rendered. --}}
@php
    $panLabel = 'Tax Registration No. (PAN)';
    $descriptionLabel = 'Description';
    $bankLabel = 'Bank Transfer';
    $signatureLabel = '(Authorized Signature)';
@endphp
<div class="invoice-page">
    @include('pdf.ird._company')

    <div class="title-bar section">
        <div class="title">INVOICE</div>
        @if($sale->status === 'cancelled')
            <div><span class="cancelled">Cancelled</span></div>
        @endif
    </div>

    <table class="info-section section">
        <tr>
            <td class="half">
                <div class="section-title">Buyer Details</div>
                <table>
                    <tr class="info-row"><td class="info-label">Name</td><td class="info-colon">:</td><td>{{ $buyerName }}</td></tr>
                    <tr class="info-row"><td class="info-label">Address</td><td class="info-colon">:</td><td>{{ $buyerAddress }}</td></tr>
                    <tr class="info-row"><td class="info-label">Tax Reg. No. (PAN)</td><td class="info-colon">:</td><td>{{ $buyerPan }}</td></tr>
                </table>
            </td>
            <td class="half">
                <div class="section-title">Invoice Details</div>
                <table>
                    <tr class="info-row"><td class="info-label">Invoice Number</td><td class="info-colon">:</td><td>{{ $documentNumber }}</td></tr>
                    <tr class="info-row"><td class="info-label">Transaction Date</td><td class="info-colon">:</td><td>{{ $dateLabel }}</td></tr>
                    <tr class="info-row"><td class="info-label">Invoice Issue Date</td><td class="info-colon">:</td><td>{{ $dateLabel }}</td></tr>
                    @if($printFiscalYearName)
                        <tr class="info-row"><td class="info-label">Fiscal Year</td><td class="info-colon">:</td><td>{{ $printFiscalYearName }}</td></tr>
                    @endif
                    @if($sale->chalani_number)
                        <tr class="info-row"><td class="info-label">Chalani No.</td><td class="info-colon">:</td><td>{{ $sale->chalani_number }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @include('pdf.ird._items')

    <table class="footer-wrap">
        <tr>
            @include('pdf.ird._payment')
            <td style="width: 50%; padding: 0;">
                <table class="totals">
                    <tr><td>Sub Total</td><td>{{ $grossSubtotal->format() }}</td></tr>
                    <tr><td>Discount</td><td>{{ $totalDiscount->format() }}</td></tr>
                    <tr class="net"><td>Grand Total</td><td>{{ $total->format() }}</td></tr>
                    @if($tds->isPositive())
                        <tr><td>TDS Withheld</td><td>-{{ $tds->format() }}</td></tr>
                        <tr class="net"><td>Net Receivable</td><td>{{ $netPayable->format() }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @include('pdf.ird._words')
    @include('pdf.ird._signature')
</div>
