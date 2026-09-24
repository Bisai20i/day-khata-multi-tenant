{{-- ABBREVIATED TAX INVOICE, ported from legacy bills/abit-invoice-print-preview.
     It carries no buyer information and no VAT breakdown. --}}
@php
    use App\Support\Money\Money;

    $panLabel = 'PAN No.';
    $descriptionLabel = 'Description of Goods';
    $signatureLabel = "(Seller's Signature)";
@endphp
<div class="invoice-page">
    @include('pdf.ird._company')

    <div class="title-bar section">
        <div class="title">ABBREVIATED TAX INVOICE</div>
        @if($sale->status === 'cancelled')
            <div><span class="cancelled">Cancelled</span></div>
        @endif
    </div>

    <table class="info-section section">
        <tr>
            <td class="half">
                <table>
                    <tr class="info-row"><td class="info-label">Invoice Number</td><td class="info-colon">:</td><td>{{ $documentNumber }}</td></tr>
                    <tr class="info-row"><td class="info-label">Tax Rate</td><td class="info-colon">:</td><td>{{ Money::of($sale->vat_rate)->toString() }}%</td></tr>
                </table>
            </td>
            <td class="half">
                <table>
                    <tr class="info-row"><td class="info-label">Date</td><td class="info-colon">:</td><td>{{ $dateLabel }}</td></tr>
                    @if($printFiscalYearName)
                        <tr class="info-row"><td class="info-label">Fiscal Year</td><td class="info-colon">:</td><td>{{ $printFiscalYearName }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    {{-- IRD-prescribed boxed PAN digits, one box per digit. --}}
    <table class="pan-section section">
        <tr>
            <td class="pan-label">Taxpayer's PAN:</td>
            <td>
                <table class="pan-boxes">
                    <tr>
                        @foreach(str_split(str_pad((string) $company->pan_vat_number, 9, ' ', STR_PAD_LEFT)) as $digit)
                            <td>{{ $digit }}</td>
                        @endforeach
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @include('pdf.ird._items')

    <table class="footer-wrap">
        <tr>
            <td style="width: 50%;"></td>
            <td style="width: 50%; padding: 0; border-left: 1.5px solid #000;">
                <table class="totals">
                    <tr><td>Discount</td><td>{{ $totalDiscount->format() }}</td></tr>
                    <tr class="net"><td>Grand Total</td><td>{{ $total->format() }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="legal-note section">
        <strong>Note:</strong> This invoice shall not be issued for the sale of goods or services where the taxable value exceeds NPR 10,000.
    </div>

    @include('pdf.ird._words')
    @include('pdf.ird._signature')
</div>
