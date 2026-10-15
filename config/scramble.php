<?php

declare(strict_types=1);

/*
| Scramble (dedoc/scramble 0.13) generates the OpenAPI 3.1 document from routes, FormRequests,
| inline validation and JsonResources. App\Docs\OpenApiDocument post-processes it (description,
| error table, security, examples). Served at /docs (offline Swagger UI) and /docs/openapi.json —
| see routes/web/docs.php. Scramble's own Stoplight UI (/docs/api) needs a CDN and is secondary.
*/
return [
    'api_path' => 'api/v1',

    'api_domain' => null,

    'export_path' => 'docs/openapi.json',

    'cache' => [
        'key' => 'scramble.openapi',
        'store' => 'file',
    ],

    'info' => [
        'version' => '1.0.0',
        'description' => 'EPFD (Easy Peasy Flyer Distribution) REST API v1.',
    ],

    'ui' => [
        'title' => 'EPFD API',
    ],

    'dev_tools' => [
        'enabled' => false,
    ],

    'renderer' => 'elements',

    'renderers' => [
        'elements' => [
            'view' => 'scramble::docs',
            'theme' => 'light',
            'hideTryIt' => false,
            'hideSchemas' => false,
            'logo' => '/brand/logo.png',
            'tryItCredentialsPolicy' => 'include',
            'layout' => 'responsive',
            'router' => 'hash',
        ],
        'scalar' => [
            'view' => 'scramble::scalar',
            'cdn' => 'https://cdn.jsdelivr.net/npm/@scalar/api-reference',
            'theme' => 'laravel',
            'proxyUrl' => '',
            'darkMode' => false,
            'showDeveloperTools' => 'never',
            'agent' => ['disabled' => true],
            'credentials' => 'include',
        ],
    ],

    'servers' => null,

    'enum_cases_description_strategy' => 'description',

    'enum_cases_names_strategy' => false,

    'flatten_deep_query_parameters' => true,

    // Publicly viewable for the prototype.
    'middleware' => ['web'],

    'extensions' => [],

    'security_strategy' => null,
];
