{{--
    Stylesheet for the three IRD invoice formats (tax, PAN, abbreviated),
    ported from the legacy day_khata bills/*-print-preview views. Sizes are
    the legacy $billSample scale: A4 is the standard sheet, A5 the small one.
    DomPDF has neither flexbox nor CSS variables, so the legacy flex rows are
    tables here and every value is spelled out; the look is otherwise the
    legacy one: black outer rules, 1px column rules, Arial.
--}}
@php
    $a5 = ($company->print_paper_size ?? 'a4') === 'a5';
    $font = $a5 ? '9.5px' : '12.5px';
    $thick = $a5 ? '1.2px' : '1.5px';
@endphp
<style>
    @page { size: {{ $a5 ? 'A5' : 'A4' }} portrait; margin: {{ $a5 ? '6mm' : '10mm' }}; }
    * { box-sizing: border-box; }
    body { margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; font-size: {{ $font }}; color: #111; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }

    .invoice-page { border: {{ $thick }} solid #000; }
    .section { border-bottom: {{ $thick }} solid #000; }

    .header td { padding: {{ $a5 ? '6px 8px' : '12px 16px' }}; }
    .company-name { margin: 0; font-size: {{ $a5 ? '13px' : '20px' }}; font-weight: bold; }
    .company-info p { margin: 2px 0; font-size: {{ $font }}; }
    .company-logo { max-height: {{ $a5 ? '32px' : '46px' }}; max-width: 180px; margin-bottom: 4px; }
    .copy-note { text-align: right; font-size: {{ $font }}; text-transform: uppercase; font-weight: bold; white-space: nowrap; }

    .title-bar { text-align: center; padding: {{ $a5 ? '5px 0' : '6px 0' }}; }
    .title-bar .title { font-size: {{ $a5 ? '13px' : '17px' }}; font-weight: bold; letter-spacing: {{ $a5 ? '2px' : '3px' }}; }
    .pan-note { display: inline-block; margin-top: 4px; border: 1px solid #000; padding: 2px 12px; font-weight: bold; font-size: {{ $font }}; }
    .cancelled { display: inline-block; margin-top: 4px; border: 1px solid #a30000; color: #a30000; padding: 1px 10px; font-weight: bold; text-transform: uppercase; font-size: {{ $font }}; }

    .info-section td.half { width: 50%; padding: {{ $a5 ? '5px 8px' : '6px 12px' }}; font-size: {{ $font }}; }
    .section-title { font-weight: bold; text-decoration: underline; margin-bottom: 6px; }
    .info-row td { padding: 2px 0; font-size: {{ $font }}; }
    .info-label { width: {{ $a5 ? '110px' : '140px' }}; }
    .info-colon { width: 10px; }

    .pan-section td { padding: {{ $a5 ? '6px 8px' : '8px 12px' }}; font-size: {{ $font }}; vertical-align: middle; }
    .pan-label { font-weight: bold; width: 1%; white-space: nowrap; }
    .pan-boxes { width: auto; border: {{ $thick }} solid #000; }
    .pan-boxes td { width: {{ $a5 ? '17px' : '22px' }}; height: {{ $a5 ? '17px' : '22px' }}; padding: 0; border-right: 1px solid #000; text-align: center; vertical-align: middle; font-weight: bold; font-size: {{ $a5 ? '10px' : '13px' }}; }
    .pan-boxes td:last-child { border-right: none; }

    table.items { border-bottom: {{ $thick }} solid #000; font-size: {{ $font }}; }
    table.items th, table.items td { border-right: 1px solid #000; padding: {{ $a5 ? '3px 4px' : '4px 5px' }}; }
    table.items th:last-child, table.items td:last-child { border-right: none; }
    table.items thead th { font-weight: bold; text-align: center; border-bottom: {{ $thick }} solid #000; }
    table.items tbody td { height: {{ $a5 ? '18px' : '22px' }}; }
    .num { text-align: right; }
    .center { text-align: center; }

    table.footer-wrap { border-bottom: {{ $thick }} solid #000; }
    .payment-block { width: 50%; padding: {{ $a5 ? '5px 8px' : '6px 12px' }}; border-right: {{ $thick }} solid #000; font-size: {{ $font }}; }
    .payment-options td { padding: 6px 10px 0 0; font-size: {{ $font }}; white-space: nowrap; vertical-align: middle; }
    .check-box { display: inline-block; width: {{ $a5 ? '12px' : '15px' }}; height: {{ $a5 ? '12px' : '15px' }}; border: 1px solid #000; text-align: center; font-size: 10px; font-weight: bold; line-height: {{ $a5 ? '11px' : '14px' }}; vertical-align: middle; }
    table.totals td { border: 1px solid #000; padding: {{ $a5 ? '3px 6px' : '4px 10px' }}; font-size: {{ $font }}; }
    table.totals tr:first-child td { border-top: none; }
    table.totals tr:last-child td { border-bottom: none; }
    table.totals td:first-child { border-left: none; }
    table.totals td:last-child { border-right: none; text-align: right; width: 35%; }
    table.totals tr.net td { font-weight: bold; }

    .words-remarks .row { padding: 5px {{ $a5 ? '8px' : '12px' }}; font-size: {{ $font }}; }
    .words-remarks .row + .row { border-top: 1px solid #000; }
    .legal-note { padding: {{ $a5 ? '6px 8px' : '8px 12px' }}; font-size: {{ $font }}; line-height: 1.4; }

    .sign-section td { padding: {{ $a5 ? '20px 8px 8px 8px' : '34px 16px 10px 16px' }}; font-size: {{ $font }}; }
    .sign-line { width: 30%; margin-left: 70%; text-align: center; border-top: 1px dashed #000; padding-top: 4px; }
    .footer-note { text-align: center; font-size: {{ $a5 ? '8px' : '10px' }}; padding: 6px 0; }
    .footer-note p { margin: 2px 0; }
</style>
