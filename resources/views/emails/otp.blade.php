@extends('emails.layout')
@section('title', 'Verify your email')
@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;">Verify your email</h1>
    <p style="margin:0 0 20px;">Hi {{ $name }}, enter this code to finish setting up your EPFD business account.</p>
    <p style="margin:0 0 20px;text-align:center;">
        <span style="display:inline-block;letter-spacing:10px;font-size:32px;font-weight:700;background:#f5f5f4;border-radius:12px;padding:14px 22px;font-family:'SFMono-Regular',Menlo,monospace;">{{ $code }}</span>
    </p>
    <p style="margin:0 0 8px;color:#57534e;">The code expires in {{ $minutes }} minutes. If you didn't create an account, you can ignore this email.</p>
@endsection
