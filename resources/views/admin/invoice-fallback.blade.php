@php($tz = config('epfd.timezone'))
<!doctype html>
<html lang="en-AU">
<head><meta charset="utf-8"><title>Tax invoice {{ $invoice->number }}</title>
<style>body{font:14px/1.5 system-ui,sans-serif;color:#141414;max-width:760px;margin:40px auto;padding:0 16px}table{width:100%;border-collapse:collapse;margin:24px 0}th,td{padding:8px;border-bottom:1px solid #e5e5e5;text-align:left}td.n,th.n{text-align:right}h1{font-size:22px;margin:0}.muted{color:#666}</style></head>
<body>
<h1>Tax invoice {{ $invoice->number }}</h1>
<p class="muted">{{ config('epfd.brand.name') }} · {{ config('epfd.brand.address') }} · {{ config('epfd.brand.email') }}</p>
<p><strong>Billed to:</strong> {{ $invoice->business?->name }} @if($invoice->business?->abn) (ABN {{ $invoice->business->abn }}) @endif<br>
<strong>Issued:</strong> {{ $invoice->issued_at?->tz($tz)->format('d M Y') }} · <strong>Status:</strong> {{ ucfirst($invoice->status) }}</p>
<table><thead><tr><th>Description</th><th class="n">Qty</th><th class="n">Amount (AUD)</th></tr></thead><tbody>
@foreach(($invoice->lines ?? []) as $l)
<tr><td>{{ $l['label'] ?? '' }}</td><td class="n">{{ number_format((int) ($l['quantity'] ?? 1)) }}</td><td class="n">${{ number_format(($l['amount_cents'] ?? 0) / 100, 2) }}</td></tr>
@endforeach
</tbody><tfoot>
<tr><td colspan="2" class="n">Subtotal (ex GST)</td><td class="n">${{ number_format($invoice->subtotal_cents / 100, 2) }}</td></tr>
<tr><td colspan="2" class="n">GST</td><td class="n">${{ number_format($invoice->gst_cents / 100, 2) }}</td></tr>
@if($invoice->credits_applied_cents)<tr><td colspan="2" class="n">Credits applied</td><td class="n">−${{ number_format($invoice->credits_applied_cents / 100, 2) }}</td></tr>@endif
<tr><th colspan="2" class="n">Total (incl GST)</th><th class="n">${{ number_format($invoice->total_cents / 100, 2) }}</th></tr>
</tfoot></table>
<p class="muted">Fallback rendering — the branded PDF template (resources/views/pdf/invoice.blade.php) is not available.</p>
</body></html>
