<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Docs\OpenApiDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Converts the generated OpenAPI document into a Postman v2.1 collection: one folder per tag,
 * {{baseUrl}} + {{token}} collection variables, example bodies and saved example responses.
 * The login request stores the returned token into {{token}} automatically.
 */
class ExportPostmanCommand extends Command
{
    protected $signature = 'api:postman
        {--output=docs/postman/epfd-api.postman_collection.json : Output path (relative to the project root)}
        {--openapi=docs/openapi.json : Also write the OpenAPI JSON here (empty to skip)}
        {--base-url= : Override {{baseUrl}} (default APP_URL/api/v1)}';

    protected $description = 'Export the EPFD OpenAPI spec as a Postman v2.1 collection';

    public function handle(): int
    {
        $spec = OpenApiDocument::build(fresh: true);
        $baseUrl = $this->option('base-url') ?: ($spec['servers'][0]['url'] ?? rtrim((string) config('app.url'), '/').'/api/v1');

        $folders = [];
        foreach ($spec['paths'] as $path => $ops) {
            foreach ($ops as $method => $op) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }
                $tag = $op['tags'][0] ?? 'Other';
                $folders[$tag][] = $this->item(strtoupper($method), $path, $op, $spec);
            }
        }

        $tagOrder = array_column($spec['tags'] ?? [], 'description', 'name');
        $items = [];
        foreach (array_unique([...array_keys($tagOrder), ...array_keys($folders)]) as $tag) {
            if (! empty($folders[$tag])) {
                $items[] = ['name' => $tag, 'description' => $tagOrder[$tag] ?? '', 'item' => $folders[$tag]];
            }
        }

        $collection = [
            'info' => [
                '_postman_id' => (string) Str::uuid(),
                'name' => ($spec['info']['title'] ?? 'EPFD API').' '.($spec['info']['version'] ?? ''),
                'description' => $spec['info']['description'] ?? '',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']]],
            'variable' => [
                ['key' => 'baseUrl', 'value' => $baseUrl, 'type' => 'string'],
                ['key' => 'token', 'value' => '', 'type' => 'string'],
            ],
            'item' => $items,
        ];

        $out = base_path((string) $this->option('output'));
        @mkdir(dirname($out), 0775, true);
        file_put_contents($out, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $count = array_sum(array_map('count', $folders));
        $this->info("Postman collection: {$out} ({$count} requests in ".count($items).' folders)');

        if ($openapi = $this->option('openapi')) {
            $path = base_path((string) $openapi);
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $this->info("OpenAPI: {$path} (".count($spec['paths']).' paths)');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $op
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function item(string $method, string $path, array $op, array $spec): array
    {
        $pmPath = preg_replace('/\{(\w+)\}/', ':$1', $path);
        $segments = array_values(array_filter(explode('/', (string) $pmPath)));
        $query = $vars = [];
        $headers = [['key' => 'Accept', 'value' => 'application/json']];
        foreach ($op['parameters'] ?? [] as $p) {
            $example = $p['example'] ?? OpenApiDocument::exampleFromSchema($p['schema'] ?? [], $spec, (string) $p['name']);
            match ($p['in'] ?? '') {
                'path' => $vars[] = ['key' => $p['name'], 'value' => (string) (is_scalar($example) ? $example : 1), 'description' => $p['description'] ?? ''],
                'query' => $query[] = ['key' => $p['name'], 'value' => is_scalar($example) ? (string) $example : '', 'description' => $p['description'] ?? '', 'disabled' => ! ($p['required'] ?? false)],
                'header' => $headers[] = ['key' => $p['name'], 'value' => $p['name'] === 'Idempotency-Key' ? '{{$guid}}' : (string) (is_scalar($example) ? $example : ''), 'disabled' => ! ($p['required'] ?? false) && $p['name'] !== 'Idempotency-Key'],
                default => null,
            };
        }

        $request = [
            'method' => $method,
            'header' => $headers,
            'url' => [
                'raw' => '{{baseUrl}}/'.implode('/', $segments).($query ? '?'.implode('&', array_map(fn ($q) => $q['key'].'='.$q['value'], array_filter($query, fn ($q) => ! $q['disabled']))) : ''),
                'host' => ['{{baseUrl}}'],
                'path' => $segments,
                'query' => $query,
                'variable' => $vars,
            ],
            'description' => trim(($op['description'] ?? '').(isset($op['x-permission']) && $op['x-permission'] ? "\n\nRequires: `{$op['x-permission']}`" : '')),
        ];
        if (($op['security'] ?? null) === []) {
            $request['auth'] = ['type' => 'noauth'];
        }

        $content = $op['requestBody']['content'] ?? [];
        if (isset($content['multipart/form-data'])) {
            $request['body'] = ['mode' => 'formdata', 'formdata' => [
                ['key' => 'file', 'type' => 'file', 'src' => []],
                ['key' => 'side', 'type' => 'text', 'value' => 'front'],
            ]];
        } elseif (isset($content['application/json'])) {
            $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
            $request['body'] = ['mode' => 'raw', 'raw' => json_encode($content['application/json']['example'] ?? new \stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'options' => ['raw' => ['language' => 'json']]];
        }

        $responses = [];
        foreach ($op['responses'] ?? [] as $status => $resp) {
            foreach (($resp['content'] ?? []) as $mime => $media) {
                if (! array_key_exists('example', $media)) {
                    continue;
                }
                $responses[] = [
                    'name' => $status.' '.($resp['description'] ?? ''),
                    'originalRequest' => $request,
                    'status' => (string) $status,
                    'code' => (int) $status,
                    '_postman_previewlanguage' => 'json',
                    'header' => [['key' => 'Content-Type', 'value' => $mime]],
                    'body' => json_encode($media['example'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
                break;
            }
        }

        $item = ['name' => $op['summary'] ?? "{$method} {$path}", 'request' => $request, 'response' => $responses];
        if ($method === 'POST' && $path === '/auth/login') {
            $item['event'] = [['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => [
                'const body = pm.response.json();',
                'if (body.token) { pm.collectionVariables.set("token", body.token); }',
            ]]]];
        }

        return $item;
    }
}
