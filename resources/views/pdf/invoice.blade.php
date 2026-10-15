@php($m = fn ($c) => '$'.number_format(((int) $c) / 100, 2))
<!doctype html>
<html><head><meta charset="utf-8">@include('pdf._styles')</head>
<body>
<div class="footer">{{ $brand['name'] }} · {{ $brand['address'] }} · {{ $brand['phone'] }} · {{ $brand['email'] }}</div>
<table class="head">
    <tr>
        <td>@if ($logo)<img src="{{ $logo }}" height="44">@else<strong>{{ $brand['short_name'] }}</strong>@endif
            <div class="muted" style="margin-top:8px;">{{ $brand['name'] }}<br>{{ $brand['address'] }}<br>{{ $brand['phone'] }} · {{ $brand['email'] }}@if (config('epfd.brand.abn'))<br>ABN {{ config('epfd.brand.abn') }}@endif</div>
        </td>
        <td class="r">
            <h1>Tax invoice</h1>
            <div style="margin-top:6px;"><strong>{{ $invoice->number }}</strong></div>
            <div class="muted">Issued {{ $invoice->issued_at->setTimezone($tz)->format('d M Y') }}</div>
            <div style="margin-top:6px;"><span class="badge">{{ strtoupper($invoice->status) }}</span></div>
        </td>
    </tr>
</table>

<table style="margin-top:22px;" class="head">
    <tr>
        <td style="width:55%;">
            <h2>Billed to</h2>
            <strong>{{ $invoice->billing['company'] ?? $business->name }}</strong><br>
            @if (! empty($invoice->billing['abn']))ABN {{ trim(chunk_split($invoice->billing['abn'], 3, ' ')) }}<br>@endif
            {{ $invoice->billing['address'] ?? $business->address }}
        </td>
        <td>
            <h2>For</h2>
            {{ $jobLabel }}<br>
            <span class="muted">Reference {{ $job?->reference }}</span>
            @if ($invoice->payment)<br><span class="muted">Paid {{ $invoice->payment->paid_at?->setTimezone($tz)->format('d M Y') }}@if ($invoice->payment->card_last4) · {{ ucfirst((string) $invoice->payment->card_brand) }} •••• {{ $invoice->payment->card_last4 }}@endif</span>@endif
        </td>
    </tr>
</table>

<table class="lines" style="margin-top:20px;">
    <thead><tr><th>Description</th><th class="r">Qty</th><th class="r">Unit (ex GST)</th><th class="r">Amount (ex GST)</th></tr></thead>
    <tbody>
    @foreach ($invoice->lines as $line)
        <tr>
            <td>{{ $line['label'] }}</td>
            <td class="r">{{ number_format((float) $line['quantity']) }}</td>
            <td class="r">{{ ((float) $line['unit_cents']) < 100 && ((float) $line['unit_cents']) > -100 ? rtrim(rtrim(number_format((float) $line['unit_cents'], 3), '0'), '.').'c' : $m(round((float) $line['unit_cents'])) }}</td>
            <td class="r">{{ $m($line['amount_cents']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals" style="margin-top:12px; width:46%; margin-left:54%;">
    <tr><td>Subtotal (ex GST)</td><td class="r">{{ $m($invoice->subtotal_cents) }}</td></tr>
    <tr><td>GST (10%)</td><td class="r">{{ $m($invoice->gst_cents) }}</td></tr>
    <tr class="grand"><td>Total incl. GST (AUD)</td><td class="r">{{ $m($invoice->total_cents) }}</td></tr>
    @if ($invoice->credits_applied_cents > 0)
        <tr><td class="muted">Credits applied</td><td class="r muted">−{{ $m($invoice->credits_applied_cents) }}</td></tr>
        <tr><td class="muted">Charged to card</td><td class="r muted">{{ $m($invoice->total_cents - $invoice->credits_applied_cents) }}</td></tr>
    @endif
</table>

<p class="muted" style="margin-top:30px;">Total includes GST of {{ $m($invoice->gst_cents) }}. Letterbox counts are estimates based on G-NAF. Thank you for your business.</p>
</body></html>
