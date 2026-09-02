<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>@yield('title', 'Document')</title>
<style>
    @page {
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
</style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="width: 55%;">
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
                    <div><strong>Date:</strong> {{ $documentDate }}</div>
                    @yield('doc-meta-extra')
                </div>
            </td>
        </tr>
    </table>

    <div class="divider"></div>

    @yield('content')

    @if($company->invoice_footer_note)
        <div class="footer-note">{{ $company->invoice_footer_note }}</div>
    @endif
</body>
</html>
