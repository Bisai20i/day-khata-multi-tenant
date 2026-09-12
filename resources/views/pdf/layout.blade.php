<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>@yield('title', 'Document')</title>
<style>
    @page {
        size: {{ ($company->print_paper_size ?? 'a4') === 'a5' ? 'A5' : 'A4' }};
        margin: 28px 32px;
    }

    * {
        box-sizing: border-box;
    }

    body {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 10px;
        color: #1a1a1a;
    }

    table {
        border-collapse: collapse;
        width: 100%;
    }

    .header-table td {
        vertical-align: top;
    }

    .company-logo {
        max-height: 46px;
        max-width: 180px;
        margin-bottom: 6px;
    }

    .company-name {
        font-size: 16px;
        font-weight: bold;
        color: #1a1a1a;
    }

    .company-meta {
        margin-top: 4px;
        font-size: 9px;
        color: #444;
        line-height: 1.5;
    }

    .doc-block {
        text-align: right;
    }

    .doc-title {
        font-size: 15px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .doc-meta {
        margin-top: 6px;
        font-size: 9px;
        color: #444;
        line-height: 1.6;
    }

    .doc-meta strong {
        color: #1a1a1a;
    }

    .divider {
        border-top: 2px solid #1a1a1a;
        margin: 10px 0 14px 0;
    }

    .party-table {
        margin-bottom: 14px;
    }

    .party-table td {
        vertical-align: top;
        width: 50%;
        font-size: 9px;
        line-height: 1.5;
    }

    .party-label {
        font-size: 8px;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #777;
        margin-bottom: 3px;
    }

    .party-name {
        font-size: 11.5px;
        font-weight: bold;
        margin-bottom: 2px;
    }

    .items-table th {
        background: #f0f0f0;
        border: 1px solid #ccc;
        padding: 5px 6px;
        font-size: 8.5px;
        text-transform: uppercase;
        letter-spacing: .3px;
        text-align: left;
    }

    .items-table td {
        border: 1px solid #ccc;
        padding: 5px 6px;
        font-size: 9px;
    }

    .text-right {
        text-align: right;
    }

    .text-center {
        text-align: center;
    }

    .totals-table {
        margin-top: 12px;
        width: 260px;
        float: right;
    }

    .totals-table td {
        padding: 3px 6px;
        font-size: 9.5px;
    }

    .totals-table tr.grand-total td {
        border-top: 1.5px solid #1a1a1a;
        font-weight: bold;
        font-size: 11.5px;
        padding-top: 6px;
    }

    .clearfix {
        clear: both;
    }

    .footer-note {
        margin-top: 50px;
        padding-top: 10px;
        border-top: 1px solid #ccc;
        font-size: 8.5px;
        color: #555;
        text-align: center;
        font-style: italic;
    }

    .status-badge {
        display: inline-block;
        padding: 2px 8px;
        font-size: 8px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: .5px;
        border: 1px solid #a30000;
        color: #a30000;
    }

    .narration {
        margin-top: 16px;
        font-size: 9px;
        color: #555;
    }

    .pan-section {
        margin-bottom: 12px;
        font-size: 9px;
    }

    .pan-section .pan-label {
        font-weight: bold;
        margin-bottom: 4px;
    }

    .pan-box-wrapper {
        display: flex;
        width: fit-content;
        border: 1.3px solid #1a1a1a;
    }

    .pan-box {
        width: 16px;
        height: 16px;
        border-right: 1px solid #1a1a1a;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 9px;
    }

    .pan-box:last-child {
        border-right: none;
    }

    .legal-note {
        margin-top: 10px;
        padding: 6px 8px;
        border: 1px solid #1a1a1a;
        font-size: 8.5px;
        line-height: 1.4;
    }

    .copy-stamp {
        display: inline-block;
        margin-top: 5px;
        padding: 2px 7px;
        border: 1.3px solid #1a1a1a;
        font-size: 8.5px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: .6px;
    }

    .copy-stamp.is-copy {
        border-color: #a30000;
        color: #a30000;
    }

    .date-ad {
        color: #777;
    }

    .amount-in-words {
        clear: both;
        margin-top: 12px;
        padding: 6px 8px;
        border: 1px solid #1a1a1a;
        font-size: 9px;
        line-height: 1.4;
    }

</style>
</head>
<body>
    @php
        // DomPDF's `enable_remote` is off (config/dompdf.php), so the
        // browser-facing logo_url (a full http(s) URL, for the Settings page
        // preview) won't resolve here - PDF rendering resolves the logo from
        // its local filesystem path instead, which works regardless of that
        // setting since it's never treated as a remote fetch.
        $logoPath = $company->logo_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->path($company->logo_path)
            : null;

        // Contract C9 print-compliance variables. Every one of them has a
        // safe default derived from what the layout already required, so a
        // document view whose controller has not been wired up yet still
        // renders - it just shows an Original stamp and no fiscal year,
        // rather than blowing up mid-print.
        $printCopyNumber = max(1, (int) ($copyNumber ?? 1));
        $printDateAd = $dateAd ?? $documentDate;
        $printDateBs = $dateBs ?? \App\Support\NepaliCalendar::formatBs($printDateAd);
        $printFiscalYearName = $fiscalYearName ?? null;
        $printAmountInWords = $amountInWords ?? null;
    @endphp
    <table class="header-table">
        <tr>
            <td style="width: 55%;">
                @if($logoPath)
                    <img src="{{ $logoPath }}" alt="{{ $company->company_name }} logo" class="company-logo">
                @endif
                <div class="company-name">{{ $company->company_name }}</div>
                <div class="company-meta">
                    @if($company->address)
                        {{ $company->address }}<br>
                    @endif
                    @if($company->phone)
                        Phone: {{ $company->phone }}<br>
                    @endif
                    @if($company->email)
                        {{ $company->email }}<br>
                    @endif
                    @if($company->pan_vat_number)
                        PAN/VAT: {{ $company->pan_vat_number }}
                    @endif
                </div>
            </td>
            <td class="doc-block" style="width: 45%;">
                <div class="doc-title">@yield('title', 'Document')</div>
                <div class="doc-meta">
                    <div><strong>No:</strong> {{ $documentNumber }}</div>
                    {{-- BS first, AD beside it: the Bikram Sambat date is the
                         one the IRD reads, the AD date is the cross-reference. --}}
                    @if($printDateBs !== '')
                        <div>
                            <strong>Date (BS):</strong> {{ $printDateBs }}
                            <span class="date-ad">(AD {{ $printDateAd }})</span>
                        </div>
                    @else
                        <div><strong>Date (AD):</strong> {{ $printDateAd }}</div>
                    @endif
                    @if($printFiscalYearName)
                        <div><strong>Fiscal Year:</strong> {{ $printFiscalYearName }}</div>
                    @endif
                    @yield('doc-meta-extra')
                </div>
                {{-- Only the first print is the original; every reprint has to
                     say so on the face of the document. --}}
                <div class="copy-stamp{{ $printCopyNumber > 1 ? ' is-copy' : '' }}">
                    {{ $printCopyNumber > 1 ? 'Copy of Original - '.($printCopyNumber - 1) : 'Original' }}
                </div>
            </td>
        </tr>
    </table>

    <div class="divider"></div>

    @yield('content')

    {{-- Rendered here rather than inside each document view so that every
         invoice and note picks the words up from its controller alone. It
         lands directly under the totals block, which is where a Nepali bill
         carries it. --}}
    @if($printAmountInWords)
        <div class="amount-in-words"><strong>Amount in Words:</strong> {{ $printAmountInWords }}</div>
    @endif

    @if($company->invoice_footer_note)
        <div class="footer-note">{{ $company->invoice_footer_note }}</div>
    @endif
</body>
</html>
