<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color: #1f2937;">
    <p>Hi {{ $adminName }},</p>
    <p>Your {{ $companyName }} account is ready. You can sign in here:</p>
    <p><a href="{{ $loginUrl }}">{{ $loginUrl }}</a></p>
</body>
</html>
