<?php

declare(strict_types=1);

use App\Domain\Gnaf\AddressClassifier;

function classifier(array $overrides = []): AddressClassifier
{
    return new AddressClassifier(array_merge((array) config('epfd.gnaf.rules'), $overrides));
}

function row(array $r = []): array
{
    return array_merge([
        'ADDRESS_DETAIL_PID' => 'GANSW1', 'ALIAS_PRINCIPAL' => 'P', 'DATE_RETIRED' => null, 'FLAT_TYPE_CODE' => null, 'FLAT_NUMBER' => null,
        'PRIMARY_SECONDARY' => null, 'LOCALITY_PID' => 'loc1', 'STREET_LOCALITY_PID' => 'NSW1', 'NUMBER_FIRST' => '10',
        'ADDRESS_SITE_PID' => null, 'LONGITUDE' => '151.03', 'LATITUDE' => '-33.81',
    ], $r);
}

describe('rule 1 — principal, non-retired, geocoded rows only', function () {
    it('imports a principal address', fn () => expect(classifier()->shouldImport(row()))->toBeTrue());
    it('skips aliases', fn () => expect(classifier()->shouldImport(row(['ALIAS_PRINCIPAL' => 'A'])))->toBeFalse());
    it('skips retired records', fn () => expect(classifier()->shouldImport(row(['DATE_RETIRED' => '2024-01-01'])))->toBeFalse());
    it('skips rows without a default geocode', fn () => expect(classifier()->shouldImport(row(['LONGITUDE' => null])))->toBeFalse());
    it('can be configured to keep aliases', fn () => expect(classifier(['principal_only' => false])->shouldImport(row(['ALIAS_PRINCIPAL' => 'A'])))->toBeTrue());
});

describe('rule 2 — house vs unit', function () {
    it('treats an address without a flat component as a house', fn () => expect(classifier()->kind(row()))->toBe('house'));
    it('treats a flat number as a unit', fn () => expect(classifier()->kind(row(['FLAT_NUMBER' => '3'])))->toBe('unit'));
    it('treats a flat type alone as a unit', fn () => expect(classifier()->kind(row(['FLAT_TYPE_CODE' => 'UNIT'])))->toBe('unit'));
    it('treats a secondary address as a unit', fn () => expect(classifier()->kind(row(['PRIMARY_SECONDARY' => 'S'])))->toBe('unit'));
    it('honours secondary_is_unit = false', fn () => expect(classifier(['secondary_is_unit' => false])->kind(row(['PRIMARY_SECONDARY' => 'S'])))->toBe('house'));
});

describe('rule 3 — no double counting of unit buildings', function () {
    $rows = [
        row(['ADDRESS_DETAIL_PID' => 'P1', 'PRIMARY_SECONDARY' => 'P']),
        row(['ADDRESS_DETAIL_PID' => 'S1', 'FLAT_NUMBER' => '1', 'PRIMARY_SECONDARY' => 'S']),
        row(['ADDRESS_DETAIL_PID' => 'S2', 'FLAT_NUMBER' => '2', 'PRIMARY_SECONDARY' => 'S']),
        row(['ADDRESS_DETAIL_PID' => 'H1', 'NUMBER_FIRST' => '12']),
    ];

    it('flags primaries via PRIMARY_SECONDARY links', function () use ($rows) {
        $flags = classifier()->primariesWithUnits($rows, [['P1', 'S1'], ['P1', 'S2']]);
        expect($flags)->toBe(['P1' => true, 'S1' => false, 'S2' => false, 'H1' => false]);
    });

    it('falls back to grouping by street number when no links exist', function () use ($rows) {
        $flags = classifier()->primariesWithUnits($rows);
        expect($flags['P1'])->toBeTrue()->and($flags['H1'])->toBeFalse();
    });

    it('groups by address site when present', function () {
        $flags = classifier()->primariesWithUnits([
            row(['ADDRESS_DETAIL_PID' => 'A', 'ADDRESS_SITE_PID' => 'site9', 'NUMBER_FIRST' => '1']),
            row(['ADDRESS_DETAIL_PID' => 'B', 'ADDRESS_SITE_PID' => 'site9', 'NUMBER_FIRST' => '1A', 'FLAT_NUMBER' => '4']),
        ]);
        expect($flags['A'])->toBeTrue();
    });

    it('excludes a flagged primary from letterbox counts so 2 units + 1 house = 3 letterboxes', function () use ($rows) {
        $c = classifier();
        $flags = $c->primariesWithUnits($rows, [['P1', 'S1'], ['P1', 'S2']]);
        $count = collect($rows)->filter(fn ($r) => $c->countsAsLetterbox([
            'address_kind' => $c->kind($r), 'is_primary_with_units' => $flags[$r['ADDRESS_DETAIL_PID']], 'is_commercial' => $c->isCommercial($r),
        ]))->count();
        expect($count)->toBe(3);
    });

    it('counts a unit building once when include_unit_buildings_as_single_letterbox = true', function () use ($rows) {
        $c = classifier(['include_unit_buildings_as_single_letterbox' => true]);
        $flags = $c->primariesWithUnits($rows, [['P1', 'S1'], ['P1', 'S2']]);
        $count = collect($rows)->filter(fn ($r) => $c->countsAsLetterbox([
            'address_kind' => $c->kind($r), 'is_primary_with_units' => $flags[$r['ADDRESS_DETAIL_PID']], 'is_commercial' => false,
        ]))->count();
        expect($count)->toBe(2); // building primary + standalone house
    });
});

describe('rule 5 — commercial exclusion', function () {
    it('flags shops as commercial and excludes them', function () {
        $c = classifier();
        $r = row(['FLAT_TYPE_CODE' => 'SHOP', 'FLAT_NUMBER' => '2']);
        expect($c->isCommercial($r))->toBeTrue()
            ->and($c->countsAsLetterbox(['address_kind' => 'unit', 'is_primary_with_units' => false, 'is_commercial' => true]))->toBeFalse();
    });

    it('keeps commercial addresses when the flag is off', fn () => expect(
        classifier(['exclude_commercial_when_available' => false])->countsAsLetterbox(['address_kind' => 'unit', 'is_primary_with_units' => false, 'is_commercial' => true])
    )->toBeTrue());
});

it('emits SQL fragments that reflect the same flags', function () {
    expect(classifier()->countableSql('a'))->toContain('NOT a.is_commercial')->toContain('NOT a.is_primary_with_units')
        ->and(classifier(['exclude_commercial_when_available' => false])->countableSql('a'))->not->toContain('is_commercial')
        ->and(classifier()->importFilterSql('ad'))->toContain("ad.alias_principal = 'P'");
});
