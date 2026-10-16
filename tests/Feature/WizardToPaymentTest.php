<?php

declare(strict_types=1);

use App\Models\Campaign;
use App\Models\PrintOrder;
use App\Models\PrintProduct;
use App\Support\Geo;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

// The Rydalmere test area is a ~30 km walk; the per-area walk limit has its own tests (WalkLimitTest).
beforeEach(fn () => config(['epfd.areas.max_walk_km' => 100]));

function webPay(array $overrides = []): array
{
    return array_replace_recursive([
        'billing' => ['company' => 'Test Biz', 'abn' => null, 'address' => '257 Victoria Road, Rydalmere NSW 2116'],
        'card' => ['number' => '4242 4242 4242 4242', 'exp' => '12/30', 'cvc' => '123', 'name' => 'Tess Tester', 'country' => 'AU'],
        'save_card' => true,
        'use_credits' => false,
        'accept_terms' => true,
    ], $overrides);
}

describe('distribution wizard → payment (web)', function () {
    it('creates a draft, draws an area, sets details and pays', function () {
        $user = businessUser();
        $this->actingAs($user);

        $this->post('/app/campaigns')->assertRedirect();
        $campaign = Campaign::where('business_id', $user->business_id)->latest('id')->firstOrFail();
        expect($campaign->status)->toBe('draft')->and($campaign->name)->toStartWith('Campaign ');

        // Audience: server re-estimates the polygon (never trusts client counts).
        $this->putJson("/app/campaigns/{$campaign->id}/areas", ['areas' => [['name' => 'Rydalmere core', 'draw_mode' => 'pins', 'geometry' => rydalmerePolygon(),
            'estimate' => ['houses' => 1, 'units' => 1]]]])->assertSuccessful();
        $campaign->refresh();
        expect($campaign->houses)->toBeGreaterThan(500)->and($campaign->units)->toBeGreaterThan(500)->and($campaign->total_cents)->toBeGreaterThan(0);

        // Details: name uniqueness + material + coverage reprice.
        $before = $campaign->total_cents;
        $this->patchJson("/app/campaigns/{$campaign->id}", ['name' => 'Spring flyers '.uniqid(), 'material' => 'folded', 'coverage' => 'houses_units', 'available_from' => now()->addDay()->toDateString()])->assertSuccessful();
        expect($campaign->fresh()->total_cents)->toBeGreaterThan($before);

        // Declined card → error, nothing paid.
        $this->from("/app/campaigns/{$campaign->id}/confirm")
            ->post("/app/campaigns/{$campaign->id}/pay", webPay(['card' => ['number' => '4000 0000 0000 0002']]))
            ->assertSessionHasErrors();
        expect($campaign->fresh()->status)->toBe('draft');

        // Valid card → submitted, payment + invoice + saved card.
        $this->post("/app/campaigns/{$campaign->id}/pay", webPay())->assertRedirect("/app/jobs/campaigns/{$campaign->id}");
        $campaign->refresh();
        expect($campaign->status)->toBe('submitted')->and($campaign->paid_at)->not->toBeNull()
            ->and($campaign->payments()->where('status', 'succeeded')->count())->toBe(1)
            ->and($campaign->invoice)->not->toBeNull()
            ->and($campaign->invoice->total_cents)->toBe($campaign->total_cents)
            ->and($campaign->invoice->gst_cents)->toBe((int) round($campaign->subtotal_cents * 0.1))
            ->and($user->business->savedCards()->count())->toBe(1);

        // Paying twice is refused (policy/conflict) and charges nothing.
        $again = $this->post("/app/campaigns/{$campaign->id}/pay", webPay());
        expect($again->status() === 403 || session()->has('errors'))->toBeTrue()
            ->and($campaign->payments()->where('status', 'succeeded')->count())->toBe(1);
    });

    it('rejects an area beyond 20 km of the pickup', function () {
        $user = businessUser();
        $this->actingAs($user)->post('/app/campaigns');
        $campaign = Campaign::where('business_id', $user->business_id)->latest('id')->firstOrFail();
        $wollongong = Geo::polygon([[150.88, -34.43], [150.90, -34.43], [150.90, -34.42], [150.88, -34.42]]);
        $res = $this->putJson("/app/campaigns/{$campaign->id}/areas", ['areas' => [['name' => 'Far', 'draw_mode' => 'pins', 'geometry' => $wollongong]]]);
        expect($res->status())->toBeIn([422, 302]);
        expect($campaign->areas()->count())->toBe(0);
    });

    it('blocks payment for a pending business', function () {
        $user = businessUser('pending');
        $this->actingAs($user)->post('/app/campaigns');
        $campaign = Campaign::where('business_id', $user->business_id)->latest('id')->firstOrFail();
        $this->putJson("/app/campaigns/{$campaign->id}/areas", ['areas' => [['name' => 'A', 'draw_mode' => 'pins', 'geometry' => rydalmerePolygon()]]]);
        $this->post("/app/campaigns/{$campaign->id}/pay", webPay())->assertSessionHasErrors();
        expect($campaign->fresh()->status)->toBe('draft');
    });
});

describe('distribution wizard → payment (API)', function () {
    it('runs the whole flow with a token and idempotency key', function () {
        Sanctum::actingAs(businessUser());
        $id = $this->postJson('/api/v1/campaigns', ['name' => 'API run '.uniqid()])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/campaigns/{$id}/areas", ['areas' => [['name' => 'Area 1', 'draw_mode' => 'trace', 'geometry' => rydalmerePolygon()]]])->assertOk();
        $this->postJson("/api/v1/campaigns/{$id}/submit")->assertOk();

        $body = ['card' => ['number' => '4000000000000002', 'exp_month' => 12, 'exp_year' => 2030, 'cvc' => '123', 'name' => 'T'],
            'billing' => ['company' => 'X', 'address' => 'Y'], 'accept_terms' => true];
        $this->postJson("/api/v1/campaigns/{$id}/pay", $body, ['Idempotency-Key' => 'k-'.uniqid()])
            ->assertStatus(402)->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('code', 'payment_declined');

        $body['card']['number'] = '4242424242424242';
        $key = 'k-'.uniqid();
        $first = $this->postJson("/api/v1/campaigns/{$id}/pay", $body, ['Idempotency-Key' => $key])->assertSuccessful();
        expect(Campaign::find($id)->status)->toBe('submitted');
        expect($first->json('data.payment.id') ?? $first->json('data.id'))->not->toBeNull();
    });
});

describe('print wizard → payment (API)', function () {
    it('quotes, uploads artwork and pays for a print order', function () {
        Sanctum::actingAs(businessUser());
        $product = PrintProduct::where('slug', 'a5-flyers')->firstOrFail();

        $quote = $this->postJson('/api/v1/pricing/print/quote', ['product_id' => $product->id, 'gsm' => 150, 'sides' => 2, 'quantity' => 1000, 'finish' => 'gloss'])
            ->assertOk()->json('data');
        expect($quote['total_cents'])->toBe($quote['subtotal_cents'] + $quote['gst_cents'])->and($quote['meta']['earliest_delivery'])->not->toBeNull();

        $order = $this->postJson('/api/v1/print/orders', ['print_product_id' => $product->id, 'gsm' => 150, 'sides' => 2, 'quantity' => 1000, 'finish' => 'gloss',
            'delivery_name' => 'Tess', 'delivery_address' => '257 Victoria Road, Rydalmere NSW 2116', 'delivery_phone' => '0400000000'])->assertCreated()->json('data');
        expect($order['total_cents'])->toBe($quote['total_cents']);

        $png = UploadedFile::fake()->image('front.png', 600, 420);
        $this->post("/api/v1/print/orders/{$order['id']}/artwork", ['file' => $png, 'side' => 'front'], ['Accept' => 'application/json'])->assertSuccessful();
        $bad = UploadedFile::fake()->create('evil.exe', 10, 'application/x-msdownload');
        $this->post("/api/v1/print/orders/{$order['id']}/artwork", ['file' => $bad, 'side' => 'back'], ['Accept' => 'application/json'])->assertStatus(422);

        $this->patchJson("/api/v1/print/orders/{$order['id']}", ['right_to_print' => true])->assertOk();
        $this->postJson("/api/v1/print/orders/{$order['id']}/pay", [
            'card' => ['number' => '4242424242424242', 'exp_month' => 12, 'exp_year' => 2030, 'cvc' => '123', 'name' => 'T'],
            'billing' => ['company' => 'X', 'address' => 'Y'], 'accept_terms' => true, 'right_to_print' => true,
        ], ['Idempotency-Key' => 'p-'.uniqid()])->assertSuccessful();
        expect(PrintOrder::find($order['id'])->status)->toBe('submitted');
    });
});
