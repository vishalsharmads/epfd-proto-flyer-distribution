<?php

declare(strict_types=1);

use App\Domain\Campaigns\CampaignService;
use Laravel\Sanctum\Sanctum;

describe('API authentication', function () {
    it('issues a token on login and returns the user', function () {
        $user = businessUser();
        $res = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'pest'])
            ->assertOk()->assertJsonStructure(['token', 'user']);

        $this->withToken($res->json('token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', $user->email);
    });

    it('rejects bad credentials with problem+json', function () {
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@x.test', 'password' => 'nope', 'device_name' => 'pest'])
            ->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('code', 'validation_failed');
    });

    it('returns 401 problem+json without a token', function () {
        $this->getJson('/api/v1/campaigns')->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'unauthenticated')
            ->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance']);
    });

    it('revokes the token on logout', function () {
        $user = businessUser();
        $token = $user->createToken('pest')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    });

    it('refuses deactivated accounts', function () {
        $user = businessUser();
        $user->forceFill(['preferences' => ['deactivated_at' => now()->toIso8601String()]])->save();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'pest'])->assertStatus(422);
    });

    it('allows public quoting without auth', function () {
        $this->postJson('/api/v1/pricing/distribution/quote', ['houses' => 35, 'units' => 13])
            ->assertOk()->assertJsonPath('data.total_cents', 4277);
    });
});

describe('business data isolation', function () {
    it('does not expose another business\'s campaign', function () {
        $owner = businessUser();
        $campaign = app(CampaignService::class)->createDraft($owner->business, $owner);

        Sanctum::actingAs(businessUser());
        $this->getJson("/api/v1/campaigns/{$campaign->id}")->assertNotFound()->assertJsonPath('code', 'not_found');
    });

    it('blocks business users from the admin API', function () {
        Sanctum::actingAs(businessUser());
        $this->getJson('/api/v1/admin/businesses')->assertForbidden()->assertJsonPath('code', 'forbidden');
    });

    it('blocks business users from the admin panel', function () {
        $this->actingAs(businessUser())->get('/admin')->assertForbidden();
    });
});

describe('RBAC', function () {
    it('grants each role exactly its permissions', function (string $role, array $allowed, array $denied) {
        $u = staffUser($role);
        foreach ($allowed as $p) {
            expect($u->can($p))->toBeTrue("{$role} should have {$p}");
        }
        foreach ($denied as $p) {
            expect($u->can($p))->toBeFalse("{$role} should NOT have {$p}");
        }
    })->with([
        'operations' => ['operations', ['campaigns.manage', 'businesses.manage', 'print_orders.manage'], ['payments.refund', 'rate_card.manage', 'users.manage']],
        'finance' => ['finance', ['payments.refund', 'rate_card.manage', 'reports.view'], ['campaigns.manage', 'support.reply', 'users.manage']],
        'support' => ['support', ['support.reply', 'content.manage'], ['rate_card.manage', 'payments.view', 'campaigns.manage']],
        'super_admin' => ['super_admin', ['users.manage', 'rate_card.manage', 'payments.refund', 'audit.view'], []],
    ]);

    it('lets finance but not support update the rate card via the API', function () {
        $card = $this->actingAs(staffUser('finance'))->getJson('/api/v1/admin/rate-card')->assertOk()->json('data');
        $payload = ['name' => 'Pest rate card', 'data' => $card['data']];

        Sanctum::actingAs(staffUser('support'));
        $this->putJson('/api/v1/admin/rate-card', $payload)->assertForbidden()->assertJsonPath('code', 'forbidden');

        Sanctum::actingAs(staffUser('finance'));
        $this->putJson('/api/v1/admin/rate-card', $payload)->assertSuccessful();
    });

    it('lets operations approve a pending business, but not support', function () {
        $pending = businessUser('pending')->business;

        Sanctum::actingAs(staffUser('support'));
        $this->postJson("/api/v1/admin/businesses/{$pending->id}/approve")->assertForbidden();

        Sanctum::actingAs(staffUser('operations'));
        $this->postJson("/api/v1/admin/businesses/{$pending->id}/approve")->assertSuccessful();
        expect($pending->fresh()->status)->toBe('approved');
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'business', 'auditable_id' => $pending->id]);
    });

    it('applies the same RBAC to admin web pages', function () {
        $this->actingAs(staffUser('support'))->get('/admin/rate-card')->assertForbidden();
        $this->actingAs(staffUser('finance'))->get('/admin/rate-card')->assertOk();
        $this->actingAs(staffUser('finance'))->get('/admin/support')->assertForbidden();
    });

    it('rate limits login attempts', function () {
        for ($i = 0; $i < 6; $i++) {
            $last = $this->postJson('/api/v1/auth/login', ['email' => 'limit@x.test', 'password' => 'bad', 'device_name' => 'pest']);
        }
        expect($last->status())->toBe(429);
        expect($last->json('code'))->toBe('rate_limited');
    });
});

it('serves the API docs and an OpenAPI spec with every required endpoint', function () {
    $this->get('/docs')->assertOk();
    $spec = $this->getJson('/docs/openapi.json')->assertOk()->json();
    expect($spec['openapi'])->toStartWith('3.1');
    $paths = array_keys($spec['paths']);
    foreach (['/auth/login', '/pricing/distribution/quote', '/pricing/print/quote', '/suburbs', '/suburbs/{suburb}/boundary', '/addresses/search',
        '/addresses/viewport', '/areas/estimate', '/campaigns', '/campaigns/{campaign}/pay', '/campaigns/{campaign}/tracking', '/print/products',
        '/print/orders', '/payments', '/invoices', '/support/threads', '/runs/available', '/runs/{run}/claim', '/runs/{run}/gps', '/runs/{run}/submit'] as $p) {
        expect(collect($paths)->contains(fn ($x) => str_ends_with($x, $p)))->toBeTrue("missing {$p}");
    }
    expect(collect($paths)->filter(fn ($x) => str_contains($x, '/admin/'))->count())->toBeGreaterThan(10)
        ->and(collect($paths)->contains(fn ($x) => str_contains($x, 'webhooks')))->toBeTrue();
});

it('sets secure headers', function () {
    $this->get('/')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

it('treats only admin roles as staff', fn () => expect(businessUser()->isStaff())->toBeFalse()->and(staffUser('support')->isStaff())->toBeTrue());
