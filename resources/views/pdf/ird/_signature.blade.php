<table class="sign-section section">
    <tr><td><div class="sign-line">{{ $signatureLabel }}</div></td></tr>
</table>
<div class="footer-note">
    <p>(Computer generated invoice does not need signature)</p>
    @if($company->invoice_footer_note)
        <p>{{ $company->invoice_footer_note }}</p>
    @endif
</div>
