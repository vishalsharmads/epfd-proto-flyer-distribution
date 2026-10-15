<!doctype html>
<html lang="en-AU">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $brand['name'])</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,Helvetica,Arial,sans-serif;color:#141414;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f4;padding:32px 16px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
            <tr><td style="padding:0 0 20px;">
                <a href="{{ $appUrl }}" style="text-decoration:none;"><img src="{{ $logoUrl }}" alt="{{ $brand['short_name'] }}" height="40" style="display:block;height:40px;border:0;"></a>
            </td></tr>
            <tr><td style="background:#ffffff;border:1px solid #e7e5e4;border-radius:16px;padding:32px;font-size:15px;line-height:1.6;">
                @yield('content')
            </td></tr>
            <tr><td style="padding:24px 8px;font-size:12px;line-height:1.6;color:#78716c;text-align:center;">
                <strong style="color:#44403c;">{{ $brand['name'] }}</strong> — {{ $brand['tagline'] }}<br>
                {{ $brand['address'] }}<br>
                <a href="tel:{{ preg_replace('/\s+/', '', $brand['phone']) }}" style="color:#78716c;">{{ $brand['phone'] }}</a> ·
                <a href="mailto:{{ $brand['email'] }}" style="color:#78716c;">{{ $brand['email'] }}</a><br>
                <a href="{{ $appUrl }}/legal/privacy" style="color:#78716c;">Privacy</a> · <a href="{{ $appUrl }}/legal/terms" style="color:#78716c;">Terms</a>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
