@extends('emails.layout')
@section('title', 'Your design proof is ready')
@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;">Your design proof is ready</h1>
    <p style="margin:0 0 16px;">Hi {{ $name }}, our designers have uploaded <strong>proof v{{ $proof->version }}</strong> for <strong>{{ $title }}</strong> ({{ $order->reference }}).</p>
    @if ($proof->note)
        <p style="margin:0 0 16px;padding:12px 14px;background:#f5f5f4;border-radius:10px;color:#44403c;">“{{ $proof->note }}”</p>
    @endif
    <p style="margin:0 0 20px;">Review it online and either <strong>approve the design</strong> or <strong>request changes</strong>.
        @if ($revisionsLeft === null)
            Your package includes unlimited revisions.
        @elseif ($revisionsLeft > 0)
            You have {{ $revisionsLeft }} {{ $revisionsLeft === 1 ? 'revision' : 'revisions' }} left in your package.
        @else
            You've used the revisions included in your package — further changes are billed as an extra revision.
        @endif
    </p>
    <p style="margin:0;"><a href="{{ $jobUrl }}" style="display:inline-block;background:#141414;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:12px;">Review your proof</a></p>
@endsection
