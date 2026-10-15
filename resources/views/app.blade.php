<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="description" content="{{ config('epfd.brand.name') }} — {{ config('epfd.brand.tagline') }}">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0b0c0f" media="(prefers-color-scheme: dark)">
    <link rel="icon" type="image/png" href="/brand/favicon-64.png">
    <link rel="apple-touch-icon" href="/brand/apple-touch-icon.png">
    <title inertia>{{ config('epfd.brand.short_name') }}</title>
    <script>
        // Apply theme before paint (no flash). Preference: localStorage → system.
        (function () {
            try {
                var t = localStorage.getItem('epfd-theme');
                var dark = t ? t === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', dark);
            } catch (e) {}
        })();
    </script>
    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
    @inertiaHead
</head>
<body class="min-h-dvh">
    @inertia
</body>
</html>
