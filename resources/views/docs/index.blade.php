<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EPFD API</title>
    <meta name="description" content="Interactive documentation for the Easy Peasy Flyer Distribution REST API v1.">
    <link rel="icon" href="/brand/favicon-64.png">
    <link rel="stylesheet" href="/vendor/swagger-ui/swagger-ui.css">
    <style>
        :root { --epfd-primary: #E00B33; --epfd-ink: #141414; --epfd-accent: #FFC446; --epfd-bg: #ffffff; --epfd-muted: #5b5b5b; }
        @media (prefers-color-scheme: dark) { :root { --epfd-bg: #ffffff; } }
        html, body { margin: 0; background: var(--epfd-bg); color: var(--epfd-ink); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        /* Phones: wide markdown tables in the API description scroll inside themselves instead of squeezing to one word per line. */
        @media (max-width: 640px) { .swagger-ui .markdown table, .swagger-ui .renderedMarkdown table { display: block; overflow-x: auto; } }
        .epfd-bar { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; padding: 12px 20px; border-bottom: 3px solid var(--epfd-primary); background: #fff; position: sticky; top: 0; z-index: 10; }
        .epfd-bar img { height: 40px; width: auto; }
        .epfd-bar h1 { font-size: 18px; margin: 0; font-weight: 700; }
        .epfd-bar h1 span { color: var(--epfd-muted); font-weight: 500; font-size: 14px; margin-left: 6px; }
        .epfd-bar nav { margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; }
        .epfd-bar a.btn { text-decoration: none; font-size: 13px; font-weight: 600; padding: 7px 12px; border-radius: 8px; border: 1px solid #ddd; color: var(--epfd-ink); }
        .epfd-bar a.btn.primary { background: var(--epfd-primary); border-color: var(--epfd-primary); color: #fff; }
        .epfd-bar a.btn:focus-visible { outline: 3px solid var(--epfd-accent); outline-offset: 2px; }
        .swagger-ui .topbar { display: none; }
        .swagger-ui .info .title { color: var(--epfd-ink); }
        .swagger-ui .btn.authorize { color: var(--epfd-primary); border-color: var(--epfd-primary); }
        .swagger-ui .btn.authorize svg { fill: var(--epfd-primary); }
        .swagger-ui .opblock-tag { border-bottom-color: #eee; }
        .swagger-ui .info a, .swagger-ui .markdown a { color: var(--epfd-primary); }
        .swagger-ui .wrapper { padding: 0 16px; max-width: 1400px; }
        .swagger-ui table { display: block; overflow-x: auto; }
        #swagger-ui { min-height: 60vh; }
        .epfd-fallback { padding: 24px 20px; color: var(--epfd-muted); }
    </style>
</head>
<body>
<header class="epfd-bar">
    <img src="/brand/logo.png" alt="Easy Peasy Flyer Distribution">
    <h1>EPFD API <span>v1.0.0 · OpenAPI 3.1</span></h1>
    <nav aria-label="API resources">
        <a class="btn" href="/docs/openapi.json">openapi.json</a>
        <a class="btn" href="/docs/postman.json" download="epfd-api.postman_collection.json">Postman collection</a>
        <a class="btn primary" href="#/Auth/auth.login">Get a token</a>
    </nav>
</header>
<main id="swagger-ui"><p class="epfd-fallback">Loading API reference… If nothing appears, run <code>node scripts/copy-swagger-ui.mjs</code> or open <a href="/docs/openapi.json">/docs/openapi.json</a>.</p></main>
<script src="/vendor/swagger-ui/swagger-ui-bundle.js"></script>
<script src="/vendor/swagger-ui/swagger-ui-standalone-preset.js"></script>
<script>
    (function () {
        function xsrf() {
            var m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
            return m ? decodeURIComponent(m[1]) : null;
        }
        window.ui = SwaggerUIBundle({
            url: '/docs/openapi.json',
            dom_id: '#swagger-ui',
            deepLinking: true,
            persistAuthorization: true,
            displayRequestDuration: true,
            docExpansion: 'none',
            filter: true,
            tryItOutEnabled: false,
            defaultModelsExpandDepth: 0,
            presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
            plugins: [SwaggerUIBundle.plugins.DownloadUrl],
            layout: 'BaseLayout',
            requestInterceptor: function (req) {
                // Session-cookie auth (web app users): forward the XSRF token on writes.
                var t = xsrf();
                if (t) { req.headers['X-XSRF-TOKEN'] = t; }
                req.headers['Accept'] = req.headers['Accept'] || 'application/json';
                return req;
            }
        });
    })();
</script>
</body>
</html>
