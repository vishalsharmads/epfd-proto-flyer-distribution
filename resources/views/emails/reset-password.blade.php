@extends('emails.layout')
@section('title', 'Reset your password')
@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;">Reset your password</h1>
    <p style="margin:0 0 20px;">Hi {{ $name }}, we received a request to reset the password for your EPFD account.</p>
    <p style="margin:0 0 24px;"><a href="{{ $url }}" style="display:inline-block;background:#E00B33;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:12px;">Choose a new password</a></p>
    <p style="margin:0 0 8px;color:#57534e;">This link expires in {{ $minutes }} minutes. If you didn't ask for this, no action is needed.</p>
    <p style="margin:16px 0 0;font-size:12px;color:#78716c;word-break:break-all;">{{ $url }}</p>
@endsection
