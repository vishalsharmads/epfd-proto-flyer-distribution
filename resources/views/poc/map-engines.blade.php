<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Map engines comparison</title>
<style>
  .poc-nav { display:flex; gap:4px; align-items:center; padding:8px 16px; border-bottom:1px solid #d5dbd6; background:#fff; font:14px system-ui, sans-serif; }
  .poc-nav a { padding:6px 12px; border-radius:999px; color:#5a665f; text-decoration:none; font-weight:500; }
  .poc-nav a[aria-current="page"] { background:#0f6b4f; color:#fff; }
</style>
</head>
<body style="margin:0">
<nav class="poc-nav" aria-label="POC sections">
  <a href="{{ route('dev.map-engines') }}">Live POC</a>
  <a href="{{ route('dev.map-engines', ['tab' => 'compare']) }}" aria-current="page">Comparison, costs, pros &amp; cons</a>
</nav>
@include('poc.partials.compare')
</body>
</html>
