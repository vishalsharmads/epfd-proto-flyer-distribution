<?php

declare(strict_types=1);

use App\Domain\Gnaf\OpenDataCatalogue;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function fakeDataGovAu(): void
{
    Http::fake([
        'data.gov.au/data/api/3/action/package_show?id=geocoded-national-address-file-g-naf' => Http::response(['result' => [
            'title' => 'Geoscape Geocoded National Address File (G-NAF)', 'license_title' => 'Creative Commons Attribution 4.0 International',
            'metadata_modified' => '2026-08-17T22:52:44',
            'resources' => [
                ['name' => 'AUG 2026 - Geoscape G-NAF - GDA94', 'url' => 'https://data.gov.au/x/gda94.zip', 'format' => 'ZIP', 'size' => 1851126005, 'last_modified' => '2026-08-17T03:20:13'],
                ['name' => 'AUG 2026 - Geoscape G-NAF - GDA2020', 'url' => 'https://data.gov.au/x/gda2020.zip', 'format' => 'ZIP', 'size' => 1854931188, 'last_modified' => '2026-08-17T03:24:56'],
                ['name' => 'MAY 2026 - Geoscape G-NAF - GDA2020', 'url' => 'https://data.gov.au/x/may.zip', 'format' => 'ZIP', 'last_modified' => '2026-05-15T03:24:56'],
                ['name' => 'End User Licence Agreement', 'url' => 'https://data.gov.au/x/eula.pdf', 'format' => 'PDF'],
            ],
        ]]),
        'data.gov.au/data/api/3/action/package_show?id=geoscape-administrative-boundaries' => Http::response(['result' => [
            'title' => 'Geoscape Administrative Boundaries', 'license_title' => 'Creative Commons Attribution 4.0 International',
            'resources' => [
                ['name' => 'AUG26 - Geoscape Admin Boundaries - MapInfo TAB - GDA2020', 'url' => 'https://data.gov.au/x/tab.zip', 'format' => 'ZIP', 'last_modified' => '2026-08-17T04:27:02'],
                ['name' => 'AUG26 - Geoscape Admin Boundaries - ESRI Shapefile - GDA2020', 'url' => 'https://data.gov.au/x/shp.zip', 'format' => 'ZIP', 'last_modified' => '2026-08-17T04:24:39'],
            ],
        ]]),
    ]);
}

it('picks the newest GDA2020 download of each dataset from data.gov.au', function () {
    fakeDataGovAu();

    $latest = app(OpenDataCatalogue::class)->latest();

    expect($latest['gnaf']['release'])->toBe('AUG 2026')
        ->and($latest['gnaf']['download']['url'])->toBe('https://data.gov.au/x/gda2020.zip')
        ->and($latest['gnaf']['documents'])->toBe([['name' => 'End User Licence Agreement', 'url' => 'https://data.gov.au/x/eula.pdf']])
        ->and($latest['boundaries']['release'])->toBe('AUG 2026')
        ->and($latest['boundaries']['download']['url'])->toBe('https://data.gov.au/x/shp.zip');
});

it('returns null for a dataset when data.gov.au is unreachable', function () {
    Http::fake(['data.gov.au/*' => Http::response('down', 503)]);

    expect(app(OpenDataCatalogue::class)->latest())->toBe(['gnaf' => null, 'boundaries' => null]);
});

it('shows the import guide to staff who can view G-NAF status', function () {
    fakeDataGovAu();

    $this->actingAs(staffUser('operations'))->get('/admin/gnaf/guide')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/gnaf/guide')
            ->has('coverage.total')
            ->has('states')
            ->missing('latest')
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('latest.gnaf.release', 'AUG 2026')));
});

it('hides the import guide from staff without G-NAF access', function () {
    $this->actingAs(staffUser('finance'))->get('/admin/gnaf/guide')->assertForbidden();
});
