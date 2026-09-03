<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color: #1f2937;">
    <p>Your {{ $companyName }} account has been suspended and is no longer accessible.</p>
    @if ($supportEmail)
        <p>If you believe this is a mistake, please contact us at {{ $supportEmail }}.</p>
    @endif
</body>
</html>
