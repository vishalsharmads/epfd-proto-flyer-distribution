<?php

declare(strict_types=1);

namespace App\Docs;

use App\Support\ProblemJson;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Builds the EPFD OpenAPI 3.1 document: Scramble infers paths/schemas from the code, then this class
 * post-processes the array — description (auth, limits, error table, CRS, licence), servers, bearer
 * security, tags by path, RFC 9457 problem responses and request/response examples for EVERY operation
 * (curated in {@see Examples}, otherwise generated from the schema).
 *
 * Used by /docs/openapi.json (routes/web/docs.php) and `php artisan api:postman`.
 */
final class OpenApiDocument
{
    /** Unauthenticated operations (path prefixes relative to /api/v1). */
    private const PUBLIC = [
        'POST /auth/register', 'POST /auth/login', 'POST /auth/password/forgot',
        'GET /suburbs', 'GET /suburbs/{suburb}/boundary', 'GET /addresses/search', 'GET /addresses/viewport',
        'POST /areas/estimate', 'POST /pricing/distribution/quote', 'POST /pricing/print/quote',
        'GET /print/products', 'GET /print/products/{product}/options', 'POST /webhooks/{gateway}',
    ];

    /** Tag by path prefix (first match wins) + tag descriptions. */
    private const TAGS = [
        ['/auth', 'Auth', 'Register, log in (Bearer token), OTP e-mail verification, password reset.'],
        ['/business', 'Business profile', 'Profile, credit ledger, saved cards and saved areas of the caller\'s business.'],
        ['/suburbs', 'Geo & estimates', 'Suburb search/boundaries, G-NAF address search, viewport points and server-side letterbox estimates. Coordinates are GeoJSON [lng, lat].'],
        ['/addresses', 'Geo & estimates', ''],
        ['/areas', 'Geo & estimates', ''],
        ['/pricing', 'Pricing', 'Distribution and print quotes from the active rate card (ex GST + GST lines).'],
        ['/campaigns', 'Campaigns', 'Distribution campaigns: drafts, areas, readiness, payment and live tracking.'],
        ['/print', 'Print', 'Print catalogue, options/price matrix, print orders, artwork upload and payment.'],
        ['/payments', 'Payments & invoices', 'Payment history and GST tax invoices.'],
        ['/invoices', 'Payments & invoices', ''],
        ['/support', 'Support', 'Support threads between a business and EPFD staff.'],
        ['/runs', 'Distributor (stub)', 'Minimal distributor-app endpoints (users with `is_distributor = true`).'],
        ['/webhooks', 'Webhooks', 'Inbound payment-gateway webhooks (signature verified, idempotent).'],
        ['/admin/businesses', 'Admin · Businesses', 'Staff only. Approve/suspend businesses, adjust credits.'],
        ['/admin/campaigns', 'Admin · Campaigns & runs', 'Staff only. Campaign oversight and distribution runs.'],
        ['/admin/runs', 'Admin · Campaigns & runs', ''],
        ['/admin/print-orders', 'Admin · Print orders', 'Staff only. Production status, artwork review, refunds.'],
        ['/admin/artworks', 'Admin · Print orders', ''],
        ['/admin/payments', 'Admin · Payments', 'Staff only. All payments and refunds.'],
        ['/admin/rate-card', 'Admin · Pricing', 'Staff only. Versioned rate card and promo codes.'],
        ['/admin/promo-codes', 'Admin · Pricing', ''],
        ['/admin', 'Admin · Data & support', 'Staff only. G-NAF releases, serviced suburbs, support inbox, audit log.'],
    ];

    /** @return array<string, mixed> */
    public static function build(bool $fresh = false): array
    {
        $key = 'epfd:openapi:'.self::fingerprint();
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 3600, function () {
            $raw = app(Generator::class)(Scramble::getGeneratorConfig(Scramble::DEFAULT_API));

            return (new self)->enhance($raw);
        });
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public function enhance(array $spec): array
    {
        $spec['openapi'] = $spec['openapi'] ?? '3.1.0';
        $spec['info'] = [
            'title' => 'EPFD API',
            'version' => '1.0.0',
            'summary' => 'Easy Peasy Flyer Distribution — REST API v1',
            'description' => self::description(),
            'contact' => ['name' => 'EPFD Operations', 'email' => config('epfd.brand.email')],
            'license' => ['name' => 'G-NAF EULA (address data)', 'url' => config('epfd.licence.gnaf_licence_url')],
        ];
        $spec['servers'] = [['url' => rtrim((string) config('app.url'), '/').'/api/v1', 'description' => 'This environment']];
        $spec['components']['securitySchemes'] = [
            'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'Sanctum personal access token', 'description' => 'Token from `POST /auth/login` → `Authorization: Bearer <token>`.'],
            'sessionCookie' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'epfd_session', 'description' => 'First-party web app session (Sanctum stateful). Requires `X-XSRF-TOKEN` on writes.'],
        ];
        $spec['security'] = [['bearerAuth' => []], ['sessionCookie' => []]];
        $spec['components']['schemas']['Problem'] = [
            'type' => 'object',
            'description' => 'RFC 9457 problem details (`application/problem+json`).',
            'properties' => [
                'type' => ['type' => 'string', 'examples' => ['/docs#errors-validation_failed']],
                'title' => ['type' => 'string'],
                'status' => ['type' => 'integer'],
                'detail' => ['type' => 'string'],
                'instance' => ['type' => 'string'],
                'code' => ['type' => 'string', 'enum' => array_keys(ProblemJson::CODES)],
                'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
            ],
            'required' => ['type', 'title', 'status', 'detail', 'code'],
        ];
        unset($spec['components']['responses']);

        $reqEx = Examples::requests();
        $resEx = Examples::responses();
        $usedTags = [];

        foreach ($spec['paths'] as $path => &$ops) {
            foreach ($ops as $method => &$op) {
                if (! is_array($op) || ! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }
                $id = strtoupper($method).' '.$path;
                $public = in_array($id, self::PUBLIC, true);

                $tag = self::tagFor($path);
                $op['tags'] = [$tag];
                $usedTags[$tag] = true;

                if (! empty($op['summary']) && empty($op['description']) && str_contains($op['summary'], "\n")) {
                    [$op['summary'], $op['description']] = array_map('trim', explode("\n", $op['summary'], 2));
                }
                $op['summary'] = rtrim((string) ($op['summary'] ?? Str::headline($op['operationId'] ?? $id)), '.');
                $op['x-permission'] = self::permissionFor($id);
                if ($public) {
                    $op['security'] = [];
                }

                // Request body examples.
                if (isset($op['requestBody']['content'])) {
                    foreach ($op['requestBody']['content'] as $mime => &$media) {
                        $media['example'] = $reqEx[$id] ?? ($mime === 'multipart/form-data'
                            ? ['file' => '(binary) flyer-front.pdf', 'side' => 'front']
                            : self::exampleFromSchema($media['schema'] ?? [], $spec));
                    }
                    unset($media);
                } elseif (isset($reqEx[$id])) {
                    $op['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object'], 'example' => $reqEx[$id]]]];
                }
                if ($id === 'POST /webhooks/{gateway}') {
                    $op['parameters'][] = ['name' => 'X-EPFD-Signature', 'in' => 'header', 'required' => false, 'description' => 'Mock gateway: hex HMAC-SHA256 of the raw body with `MOCK_WEBHOOK_SECRET`.', 'schema' => ['type' => 'string'], 'example' => '3f1c…'];
                    $op['parameters'][] = ['name' => 'Stripe-Signature', 'in' => 'header', 'required' => false, 'description' => 'Stripe gateway signature header.', 'schema' => ['type' => 'string']];
                }
                if (str_ends_with($path, '/pay')) {
                    $op['parameters'][] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'description' => 'Any unique string (e.g. a UUID). Retrying with the same key returns the original payment instead of charging twice.', 'schema' => ['type' => 'string'], 'example' => '6b0f7c1e-3f8b-4b7a-9d55-0f5a2c9d3e11'];
                }
                if ($id === 'GET /invoices/{invoice}') {
                    $op['parameters'][] = ['name' => 'format', 'in' => 'query', 'required' => false, 'description' => '`pdf` to download the PDF (when the PDF template is installed).', 'schema' => ['type' => 'string', 'enum' => ['json', 'pdf']]];
                }

                // Success responses: ensure an example.
                $responses = $op['responses'] ?? [];
                foreach ($responses as $status => &$resp) {
                    if (isset($resp['$ref']) || (int) $status >= 400) {
                        continue;
                    }
                    if ((int) $status === 204) {
                        continue;
                    }
                    $resp['description'] = $resp['description'] ?: 'Success';
                    if (! isset($resp['content'])) {
                        $resp['content'] = ['application/json' => ['schema' => ['type' => 'object']]];
                    }
                    foreach ($resp['content'] as $mime => &$media) {
                        if ($mime !== 'application/json') {
                            continue;
                        }
                        $media['example'] = $resEx[$id][(int) $status] ?? self::exampleFromSchema($media['schema'] ?? [], $spec);
                    }
                    unset($media);
                }
                unset($resp);
                foreach (($resEx[$id] ?? []) as $status => $body) {
                    if (! isset($responses[$status])) {
                        $responses[$status] = ['description' => 'Success', 'content' => ['application/json' => ['schema' => ['type' => 'object'], 'example' => $body]]];
                    }
                }
                if (! array_filter(array_keys($responses), fn ($s) => (int) $s < 300)) {
                    $responses[200] = ['description' => 'Success', 'content' => ['application/json' => ['schema' => ['type' => 'object'], 'example' => ['data' => []]]]];
                }

                // Error responses (problem+json).
                foreach (array_keys($responses) as $s) {
                    if ((int) $s >= 400) {
                        unset($responses[$s]);
                    }
                }
                foreach (self::problemsFor($id, $path, $public, isset($op['requestBody']) || ! empty($op['parameters'])) as $status => [$code, $detail]) {
                    $responses[$status] = self::problemResponse($status, $code, $detail, '/api/v1'.$path);
                }
                ksort($responses);
                $op['responses'] = $responses;
            }
            unset($op);
        }
        unset($ops);
        ksort($spec['paths']);

        $spec['tags'] = [];
        $groups = ['Business API' => [], 'Admin API' => [], 'Integrations' => []];
        foreach (self::TAGS as [, $name, $desc]) {
            if (isset($usedTags[$name]) && ! in_array($name, array_column($spec['tags'], 'name'), true)) {
                $spec['tags'][] = ['name' => $name, 'description' => $desc ?: $name];
                $group = str_starts_with($name, 'Admin') ? 'Admin API' : (in_array($name, ['Webhooks', 'Distributor (stub)'], true) ? 'Integrations' : 'Business API');
                $groups[$group][] = $name;
            }
        }
        $spec['x-tagGroups'] = array_map(fn ($g, $tags) => ['name' => $g, 'tags' => $tags], array_keys($groups), $groups);

        return $spec;
    }

    public static function description(): string
    {
        $rows = collect(ProblemJson::CODES)->map(fn ($v, $code) => "| `{$code}` | {$v[0]} | {$v[1]} |")->implode("\n");
        $attr = (string) config('epfd.licence.gnaf_attribution');
        $disclaimer = (string) config('epfd.licence.gnaf_disclaimer');
        $licenceUrl = (string) config('epfd.licence.gnaf_licence_url');

        return <<<MD
REST API for **Easy Peasy Flyer Distribution** — letterbox distribution campaigns, print orders, payments,
support and back-office operations. Base URL: `/api/v1`. All bodies are JSON (`Accept: application/json`)
except artwork upload (`multipart/form-data`).

## Authentication
* **Bearer token** — `POST /auth/login` with `email`, `password`, `device_name` returns `{token, user}`.
  Send `Authorization: Bearer <token>`. `POST /auth/logout` revokes the current token.
* **Session cookie** — the EPFD web app calls the same endpoints with its first-party session cookie
  (Laravel Sanctum stateful SPA auth; send `X-XSRF-TOKEN` on writes).
* Admin endpoints (`/admin/*`) require a staff role (super_admin, operations, finance, support) **and** the
  permission shown as `x-permission` on each operation. Distributor endpoints require `is_distributor`.
* Demo accounts (password `password`): `demo@business.test` (approved business), `admin@epfd.test`,
  `ops@epfd.test`, `finance@epfd.test`, `support@epfd.test`.

## Rate limits
| Limiter | Limit | Applies to |
|---|---|---|
| `api` | 120 requests / minute per user (or IP) | every endpoint |
| `estimate` | 240 / minute | `POST /areas/estimate`, `PUT /campaigns/{id}/areas`, `POST /business/areas` |
| `auth` | 10 / minute per IP | register, login, password reset, OTP |
| `payments` | 10 / minute | `POST …/pay` |

Exceeding a limit returns **429** `rate_limited` with a `Retry-After` header.

## Pagination
List endpoints accept `?page=` and `?per_page=` (default 20, **max 100**) and return Laravel's paginator
envelope: `data`, `links {first,last,prev,next}` and `meta {current_page, from, last_page, per_page, to, total}`.

## Idempotency
`POST /campaigns/{id}/pay` and `POST /print/orders/{id}/pay` accept an `Idempotency-Key` header. Retrying with the
same key returns the original payment instead of charging twice. Webhooks are de-duplicated by event id.

## Money
All amounts are integer **cents in AUD**. Prices are shown ex GST with a separate GST (10%) line; `total_cents` is GST-inclusive.

## Coordinates & CRS
Geometries are GeoJSON with **[longitude, latitude]** order. Data is stored and queried in **GDA2020 (EPSG:7844)**.
WGS84 lon/lat from browsers/GPS is accepted as GDA2020 — the ~1.8 m datum offset is negligible for letterbox
counting. Distances and areas are computed in the matching **MGA2020 zone (EPSG:7849–7856)**. Letterbox counts
are always computed server-side; client counts are never trusted.

## Errors <a id="errors"></a>
Errors use **RFC 9457** `application/problem+json`:

```json
{"type": "/docs#errors-validation_failed", "title": "Validation failed", "status": 422,
 "detail": "The name field is required.", "instance": "/api/v1/campaigns", "code": "validation_failed",
 "errors": {"name": ["The name field is required."]}}
```

`code` is stable and machine-readable:

| Code | HTTP | Meaning |
|---|---|---|
{$rows}

## Test payments (mock gateway)
Card `4242 4242 4242 4242` succeeds; `4000 0000 0000 0002` is declined (**402** `payment_declined`). Any future expiry / CVC.

## Address data licence
{$attr}

{$disclaimer} Licence: {$licenceUrl}
MD;
    }

    private static function tagFor(string $path): string
    {
        foreach (self::TAGS as [$prefix, $name]) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/') || str_starts_with($path, $prefix)) {
                return $name;
            }
        }

        return 'Other';
    }

    private static function permissionFor(string $id): ?string
    {
        if (! str_contains($id, ' /admin/')) {
            return str_contains($id, ' /runs') ? 'is_distributor' : null;
        }
        $map = [
            'POST /admin/businesses/{business}/credits' => 'credits.manage',
            'POST /admin/businesses/{business}/approve' => 'businesses.manage',
            'POST /admin/businesses/{business}/suspend' => 'businesses.manage',
            'POST /admin/print-orders/{order}/refund' => 'payments.refund',
            'POST /admin/payments/{payment}/refund' => 'payments.refund',
            'GET /admin/payments' => 'payments.view',
            'GET /admin/gnaf/releases' => 'gnaf.view',
            'GET /admin/support/threads' => 'support.view',
            'POST /admin/support/threads/{thread}/reply' => 'support.reply',
            'GET /admin/audit-logs' => 'audit.view',
        ];
        if (isset($map[$id])) {
            return $map[$id];
        }
        [$method, $path] = explode(' ', $id, 2);

        return match (true) {
            str_starts_with($path, '/admin/businesses') => 'businesses.view',
            str_starts_with($path, '/admin/campaigns') && $method === 'GET' => 'campaigns.view',
            str_starts_with($path, '/admin/campaigns'), str_starts_with($path, '/admin/runs') => 'campaigns.manage',
            str_starts_with($path, '/admin/print-orders') && $method === 'GET' => 'print_orders.view',
            str_starts_with($path, '/admin/print-orders'), str_starts_with($path, '/admin/artworks') => 'print_orders.manage',
            str_starts_with($path, '/admin/rate-card'), str_starts_with($path, '/admin/promo-codes') => 'rate_card.manage',
            str_starts_with($path, '/admin/suburbs') => 'service_areas.manage',
            default => null,
        };
    }

    /** @return array<int, array{0:string, 1:string}> */
    private static function problemsFor(string $id, string $path, bool $public, bool $hasInput): array
    {
        $p = [];
        if (str_starts_with($path, '/webhooks')) {
            return [400 => ['webhook_signature_invalid', 'Invalid webhook signature.'], 404 => ['not_found', 'Unknown gateway.']];
        }
        if (! $public) {
            $p[401] = ['unauthenticated', 'Authentication is required.'];
        }
        if (str_starts_with($path, '/admin')) {
            $p[403] = ['forbidden', 'User does not have the right permissions.'];
        } elseif (str_starts_with($path, '/runs')) {
            $p[403] = ['forbidden', 'Distributor access only.'];
        } elseif (str_ends_with($path, '/pay')) {
            $p[402] = ['payment_declined', 'Your card was declined.'];
            $p[403] = ['business_not_approved', 'Your business account is awaiting approval. You can save drafts, but payment is blocked until an administrator approves the account.'];
            $p[409] = ['conflict', 'This job has already been submitted.'];
        }
        if (str_contains($path, '{')) {
            $p[404] = ['not_found', 'The requested resource was not found.'];
        }
        if (in_array(explode(' ', $id)[0], ['PATCH', 'PUT', 'DELETE'], true) && preg_match('#^/(campaigns|print/orders)/#', $path)
            || preg_match('#/(submit|claim|publish|status)$#', $path)) {
            $p[409] ??= ['conflict', 'Only draft campaigns can be edited.'];
        }
        if ($hasInput) {
            $p[422] = match (true) {
                str_contains($path, 'areas') || str_contains($path, 'estimate') => ['polygon_self_intersection', 'Edges cross each other.'],
                str_contains($path, 'print') && ! str_contains($path, 'products') => ['pricing_unavailable_combination', 'That paper weight, sides and quantity combination is not available for this product.'],
                default => ['validation_failed', 'The given data was invalid.'],
            };
        }
        $p[429] = ['rate_limited', 'Rate limit exceeded.'];

        return $p;
    }

    /** @return array<string, mixed> */
    private static function problemResponse(int $status, string $code, string $detail, string $instance): array
    {
        $extra = $code === 'validation_failed' ? ['errors' => ['name' => ['The name field is required.']]] : [];

        return [
            'description' => ProblemJson::CODES[$code][1] ?? $detail,
            'content' => ['application/problem+json' => [
                'schema' => ['$ref' => '#/components/schemas/Problem'],
                'example' => Examples::problem($status, $code, $detail, $instance, $extra),
            ]],
        ];
    }

    /**
     * Small schema → example generator (handles $ref, nullable unions, enums, arrays, objects).
     *
     * @param  array<string, mixed>|object  $schema
     * @param  array<string, mixed>  $spec
     */
    public static function exampleFromSchema(mixed $schema, array $spec, string $name = '', int $depth = 0): mixed
    {
        $schema = is_object($schema) ? (array) $schema : (is_array($schema) ? $schema : []);
        if ($depth > 6) {
            return null;
        }
        if (isset($schema['$ref'])) {
            $ref = substr((string) $schema['$ref'], strlen('#/components/schemas/'));

            return self::exampleFromSchema($spec['components']['schemas'][$ref] ?? [], $spec, $name, $depth + 1);
        }
        foreach (['example', 'default'] as $k) {
            if (array_key_exists($k, $schema)) {
                return $schema[$k];
            }
        }
        if (! empty($schema['examples']) && is_array($schema['examples'])) {
            return $schema['examples'][0];
        }
        if (! empty($schema['enum'])) {
            return collect($schema['enum'])->first(fn ($v) => $v !== null);
        }
        foreach (['anyOf', 'oneOf', 'allOf'] as $k) {
            if (! empty($schema[$k])) {
                if ($k === 'allOf') {
                    $out = [];
                    foreach ($schema[$k] as $part) {
                        $v = self::exampleFromSchema($part, $spec, $name, $depth + 1);
                        $out = is_array($v) ? [...$out, ...$v] : $out;
                    }

                    return $out;
                }
                foreach ($schema[$k] as $part) {
                    if (($part['type'] ?? null) !== 'null') {
                        return self::exampleFromSchema($part, $spec, $name, $depth + 1);
                    }
                }
            }
        }
        $type = $schema['type'] ?? (isset($schema['properties']) ? 'object' : 'string');
        if (is_array($type)) {
            $type = collect($type)->first(fn ($t) => $t !== 'null') ?? 'null';
        }

        return match ($type) {
            'object' => self::objectExample($schema, $spec, $depth),
            'array' => [self::exampleFromSchema($schema['items'] ?? [], $spec, Str::singular($name), $depth + 1)],
            'integer' => self::intFor($name, $schema),
            'number' => str_contains($name, 'lng') ? 151.0365 : (str_contains($name, 'lat') ? -33.8126 : 12.5),
            'boolean' => true,
            'null' => null,
            default => self::stringFor($name, $schema),
        };
    }

    /** @param array<string, mixed> $schema */
    private static function objectExample(array $schema, array $spec, int $depth): array|object
    {
        $out = [];
        foreach (($schema['properties'] ?? []) as $prop => $sub) {
            $out[$prop] = self::exampleFromSchema($sub, $spec, (string) $prop, $depth + 1);
        }

        return $out === [] ? (object) [] : $out;
    }

    /** @param array<string, mixed> $schema */
    private static function intFor(string $name, array $schema): int
    {
        return match (true) {
            str_ends_with($name, '_cents') => 12500,
            $name === 'id' || str_ends_with($name, '_id') => 1,
            $name === 'per_page' => 20,
            $name === 'page' || $name === 'current_page' => 1,
            isset($schema['minimum']) => (int) $schema['minimum'],
            default => 1,
        };
    }

    /** @param array<string, mixed> $schema */
    private static function stringFor(string $name, array $schema): string
    {
        $format = $schema['format'] ?? '';

        return match (true) {
            $format === 'date-time' || str_ends_with($name, '_at') => '2026-10-15T07:47:42+00:00',
            $format === 'date' || in_array($name, ['deadline', 'available_from', 'earliest_delivery'], true) => '2026-10-20',
            $format === 'email' || $name === 'email' => 'demo@business.test',
            $format === 'uri' || in_array($name, ['first', 'last', 'prev', 'next', 'path', 'url'], true) => 'http://epfd.test/api/v1/campaigns?page=1',
            $format === 'binary' => '(binary)',
            $name === 'reference' => 'CMP-3CVBS8',
            $name === 'name' => 'Spring letterbox drop',
            $name === 'status' => 'draft',
            str_contains($name, 'address') => '257 Victoria Road, Rydalmere NSW 2116',
            $name === 'mobile' || str_contains($name, 'phone') => '0412345678',
            $name === 'abn' => '51824753556',
            $name === 'state' => 'NSW',
            $name === 'postcode' => '2116',
            in_array($name, ['body', 'note', 'reason', 'notes', 'description', 'message', 'detail'], true) => 'Example text',
            default => 'string',
        };
    }

    /** Cache key changes whenever routes, API controllers, resources or docs classes change. */
    private static function fingerprint(): string
    {
        $dirs = [app_path('Http/Controllers/Api'), app_path('Http/Resources'), app_path('Http/Requests/Api'), app_path('Docs')];
        $files = [base_path('routes/api.php'), base_path('routes/api_v1.php'), config_path('scramble.php'), app_path('Support/ProblemJson.php')];
        $m = 0;
        foreach ($dirs as $d) {
            if (is_dir($d)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS)) as $f) {
                    $m = max($m, $f->getMTime());
                }
            }
        }
        foreach ($files as $f) {
            $m = max($m, (int) @filemtime($f));
        }

        return md5($m.'|'.config('app.url'));
    }
}
