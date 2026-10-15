@extends('emails.layout')
@section('title', 'Payment received')
@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;">Thanks — payment received</h1>
    <p style="margin:0 0 20px;">Hi {{ $name }}, we've received your payment for <strong>{{ $jobName }}</strong>. Your tax invoice <strong>{{ $invoice->number }}</strong> is attached.</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;margin:0 0 24px;">
        <tr><td style="padding:6px 0;color:#57534e;">Subtotal (ex GST)</td><td align="right">${{ number_format($invoice->subtotal_cents / 100, 2) }}</td></tr>
        <tr><td style="padding:6px 0;color:#57534e;">GST (10%)</td><td align="right">${{ number_format($invoice->gst_cents / 100, 2) }}</td></tr>
        @if ($invoice->credits_applied_cents > 0)
            <tr><td style="padding:6px 0;color:#57534e;">Credits applied</td><td align="right">−${{ number_format($invoice->credits_applied_cents / 100, 2) }}</td></tr>
        @endif
        <tr><td style="padding:10px 0 0;border-top:1px solid #e7e5e4;font-weight:700;">Total incl. GST</td><td align="right" style="padding:10px 0 0;border-top:1px solid #e7e5e4;font-weight:700;">${{ number_format($invoice->total_cents / 100, 2) }} AUD</td></tr>
    </table>
    <p style="margin:0;"><a href="{{ $jobUrl }}" style="display:inline-block;background:#141414;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:12px;">View your job</a></p>
@endsection
