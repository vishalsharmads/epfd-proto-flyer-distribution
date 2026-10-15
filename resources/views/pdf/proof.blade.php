<!doctype html>
<html><head><meta charset="utf-8">@include('pdf._styles')</head>
<body>
<div class="footer">{{ $brand['name'] }} · {{ $brand['address'] }} · {{ $brand['phone'] }} · {{ $brand['email'] }}</div>
<table class="head">
    <tr>
        <td>@if ($logo)<img src="{{ $logo }}" height="44">@else<strong>{{ $brand['short_name'] }}</strong>@endif</td>
        <td class="r"><h1>Proof of distribution</h1><div class="muted">Generated {{ now($tz)->format('d M Y, g:i a') }}</div></td>
    </tr>
</table>

<h2>{{ $campaign->name }}</h2>
<div class="muted">{{ $campaign->reference }} · {{ $business->name }} · Status: {{ ucwords(str_replace('_', ' ', $campaign->status)) }}</div>
<div style="margin-top:4px;">{{ number_format($campaign->letterboxes) }} letterboxes (estimated) · {{ number_format($campaign->houses) }} houses · {{ number_format($campaign->units) }} units</div>

@if ($sketch)
    <div style="margin-top:14px;"><img src="{{ $sketch }}" width="480" height="260"></div>
    <div class="muted" style="font-size:8.5px;">Sketch: shaded = target areas, blue = distributor GPS tracks. Not to scale.</div>
@endif

<h2>Runs</h2>
@if ($runs->isEmpty())
    <p class="muted">Runs have not been published for this campaign yet.</p>
@else
<table class="lines">
    <thead><tr><th>Run</th><th>Status</th><th class="r">Flyers</th><th class="r">Coverage</th><th class="r">GPS points</th><th>Started</th><th>Finished</th></tr></thead>
    <tbody>
    @foreach ($runs as $r)
        <tr>
            <td><strong>{{ $r->name }}</strong><br><span class="muted">{{ $r->reference }}</span></td>
            <td>{{ ucwords(str_replace('_', ' ', $r->status)) }}</td>
            <td class="r">{{ number_format($r->flyers) }}</td>
            <td class="r">{{ $r->coverage_pct !== null ? number_format((float) $r->coverage_pct, 1).'%' : '—' }}</td>
            <td class="r">{{ number_format($r->gps_points_count) }}</td>
            <td>{{ $r->started_at?->setTimezone($tz)->format('d M Y, g:i a') ?? '—' }}</td>
            <td>{{ $r->completed_at?->setTimezone($tz)->format('d M Y, g:i a') ?? '—' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif
<p class="muted" style="margin-top:24px;">Coverage is the share of the run area's street network passed by the distributor's GPS track. Letterbox counts are estimates derived from G-NAF.</p>
</body></html>
