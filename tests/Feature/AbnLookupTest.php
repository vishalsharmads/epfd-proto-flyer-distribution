<?php

declare(strict_types=1);

use App\Services\AbnLookupService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const VALID_ABN = '51824753556';

beforeEach(fn () => Cache::flush());

it('looks up the entity on the business register and caches it', function () {
    config(['services.abr.guid' => 'guid-123']);
    Http::fake(['abr.business.gov.au/*' => Http::response('r({"Abn":"51824753556","AbnStatus":"Active","EntityName":"HARBOUR COFFEE PTY LTD","AddressState":"NSW","BusinessName":[],"Message":""})')]);

    $hit = app(AbnLookupService::class)->lookup('51 824 753 556');
    app(AbnLookupService::class)->lookup(VALID_ABN);

    expect($hit)->toBe(['abn' => '51 824 753 556', 'entity_name' => 'HARBOUR COFFEE PTY LTD', 'status' => 'Active', 'state' => 'NSW']);
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r['guid'] === 'guid-123' && $r['abn'] === VALID_ABN);
});

it('reports cancelled ABNs and unregistered ones', function () {
    config(['services.abr.guid' => 'guid-123']);
    Http::fakeSequence('abr.business.gov.au/*')
        ->push('r({"Abn":"51824753556","AbnStatus":"Cancelled","EntityName":"OLD CO","AddressState":"VIC","Message":""})')
        ->push('r({"Abn":"","Message":"Search text is not a valid ABN or ACN"})');

    expect(app(AbnLookupService::class)->lookup(VALID_ABN)['status'])->toBe('Cancelled');

    Cache::flush();
    expect(app(AbnLookupService::class)->lookup(VALID_ABN))->toBeNull();
});

it('does not cache an outage, so the next request tries again', function () {
    config(['services.abr.guid' => 'guid-123']);
    Http::fakeSequence('abr.business.gov.au/*')->pushStatus(503)
        ->push('r({"Abn":"51824753556","AbnStatus":"Active","EntityName":"BACK UP PTY LTD","AddressState":"NSW","Message":""})');

    expect(app(AbnLookupService::class)->lookup(VALID_ABN))->toBeNull()
        ->and(app(AbnLookupService::class)->lookup(VALID_ABN)['entity_name'])->toBe('BACK UP PTY LTD');
});

it('uses a fake entity locally when no GUID is set, but never in production', function () {
    config(['services.abr.guid' => null]);
    Http::fake();

    expect(app(AbnLookupService::class)->lookup(VALID_ABN)['entity_name'])->toEndWith('Pty Ltd');

    app()->detectEnvironment(fn () => 'production');
    expect(app(AbnLookupService::class)->lookup(VALID_ABN))->toBeNull();
    Http::assertNothingSent();
});

it('answers the registration hint endpoint', function () {
    config(['services.abr.guid' => 'guid-123']);
    Http::fake(['abr.business.gov.au/*' => Http::response('r({"Abn":"","Message":"No records found"})')]);

    $this->getJson('/register/abn?abn=123')->assertStatus(422);
    $this->getJson('/register/abn?abn='.VALID_ABN)->assertNotFound();
});
