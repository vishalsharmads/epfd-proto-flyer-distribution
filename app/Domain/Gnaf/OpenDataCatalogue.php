<?php

declare(strict_types=1);

namespace App\Domain\Gnaf;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Latest Geoscape releases on data.gov.au (CKAN API), so the admin import guide links to the current download
 * instead of a hard-coded URL that goes stale every quarter. Cached; returns null per dataset when offline.
 */
final class OpenDataCatalogue
{
    private const API = 'https://data.gov.au/data/api/3/action/package_show';

    /** data.gov.au package slugs, keyed by what the app imports them as. */
    public const DATASETS = [
        'gnaf' => 'geocoded-national-address-file-g-naf',
        'boundaries' => 'geoscape-administrative-boundaries',
    ];

    /**
     * @return array<string, array{title: string, page: string, licence: ?string, modified: ?string, release: ?string, download: ?array{name: string, url: string, size: ?int, published: ?string}, documents: list<array{name: string, url: string}>}|null>
     */
    public function latest(): array
    {
        return collect(self::DATASETS)->map(fn (string $slug) => $this->dataset($slug))->all();
    }

    /**
     * @return array{title: string, page: string, licence: ?string, modified: ?string, release: ?string, download: ?array{name: string, url: string, size: ?int, published: ?string}, documents: list<array{name: string, url: string}>}|null
     */
    private function dataset(string $slug): ?array
    {
        $cached = Cache::get("open-data:{$slug}");
        if ($cached !== null) {
            return $cached;
        }

        try {
            $package = Http::timeout(8)->acceptJson()->get(self::API, ['id' => $slug])->throw()->json('result');
        } catch (Throwable) {
            return null;
        }
        if (! is_array($package)) {
            return null;
        }

        $resources = collect($package['resources'] ?? []);
        $download = $resources
            ->filter(fn (array $r) => str_contains(strtoupper((string) ($r['name'] ?? '')), 'GDA2020')
                && ! str_contains(strtoupper((string) ($r['name'] ?? '')), 'TAB'))
            ->sortByDesc(fn (array $r) => $r['last_modified'] ?? $r['created'] ?? '')
            ->first();
        $documents = $resources
            ->filter(fn (array $r) => strtolower((string) ($r['format'] ?? '')) === 'pdf' || str_ends_with(strtolower((string) ($r['url'] ?? '')), '.pdf'))
            ->map(fn (array $r) => ['name' => (string) $r['name'], 'url' => (string) $r['url']])
            ->values()->all();

        $result = [
            'title' => (string) ($package['title'] ?? $slug),
            'page' => 'https://data.gov.au/data/dataset/'.$slug,
            'licence' => $package['license_title'] ?? null,
            'modified' => $package['metadata_modified'] ?? null,
            'release' => $download ? $this->releaseLabel((string) $download['name']) : null,
            'download' => $download ? [
                'name' => (string) $download['name'],
                'url' => (string) $download['url'],
                'size' => isset($download['size']) ? (int) $download['size'] : null,
                'published' => $download['last_modified'] ?? $download['created'] ?? null,
            ] : null,
            'documents' => $documents,
        ];
        Cache::put("open-data:{$slug}", $result, now()->addHours(6));

        return $result;
    }

    /** "AUG26 - Geoscape Admin Boundaries…" and "AUG 2026 - Geoscape G-NAF…" both become "AUG 2026" (GNAF_RELEASE_LABEL style). */
    public function releaseLabel(string $resourceName): ?string
    {
        if (! preg_match('/^([A-Z]{3})[A-Z]*\s?(\d{4}|\d{2})\b/i', trim($resourceName), $m)) {
            return null;
        }

        return strtoupper($m[1]).' '.(strlen($m[2]) === 2 ? '20'.$m[2] : $m[2]);
    }
}
