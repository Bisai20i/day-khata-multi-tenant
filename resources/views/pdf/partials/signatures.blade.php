{{-- Prepared / Checked / Approved signature block for financial statement
     PDFs (trial balance, income statement, balance sheet, ledger).

     Usage, at the end of a statement's content section, no variables needed:
         @include('pdf.partials.signatures')

     DomPDF renders these views (see pdf/layout.blade.php), which has no
     flex or grid, so the three columns are a fixed-layout table. Flat borders,
     no shadows. --}}
<div class="signature-block" style="margin-top: 46px; page-break-inside: avoid;">
    <table style="width: 100%; border-collapse: collapse; table-layout: fixed; page-break-inside: avoid;">
        <tr>
            @foreach(['Prepared by', 'Checked by', 'Approved by'] as $signatureLabel)
                <td style="width: 33.33%; padding: 0 18px; vertical-align: bottom; text-align: center; border: none;">
                    <div style="height: 34px; border-bottom: 1px solid #1a1a1a;">&nbsp;</div>
                    <div style="margin-top: 4px; font-size: 9px; color: #444; text-transform: uppercase; letter-spacing: .5px;">{{ $signatureLabel }}</div>
                </td>
            @endforeach
        </tr>
    </table>
</div>
