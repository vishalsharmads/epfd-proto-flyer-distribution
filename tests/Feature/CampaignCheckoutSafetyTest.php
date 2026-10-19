<?php

declare(strict_types=1);

use App\Domain\Payments\PaymentGatewayManager;
use App\Models\Campaign;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config(['epfd.areas.max_walk_km' => 100]);
    Mail::fake();
});

/** A draft campaign with one priced area, ready for the Confirm step. */
function readyCampaign($test, User $user): Campaign
{
    $test->actingAs($user)->post('/app/campaigns');
    $campaign = Campaign::where('business_id', $user->business_id)->latest('id')->firstOrFail();
    $test->putJson("/app/campaigns/{$campaign->id}/areas", ['areas' => [['name' => 'Core', 'geometry' => rydalmerePolygon()]]])->assertSuccessful();
    $test->patchJson("/app/campaigns/{$campaign->id}", ['available_from' => now()->addDay()->toDateString()])->assertSuccessful();

    return $campaign->fresh();
}

function campaignPay(array $overrides = []): array
{
    return array_replace([
        'billing' => ['company' => 'Test Biz', 'address' => '257 Victoria Road, Rydalmere NSW 2116'],
        'card' => ['number' => '4242 4242 4242 4242', 'exp' => '12/30', 'cvc' => '123', 'name' => 'Tess', 'country' => 'AU'],
        'accept_terms' => true,
    ], $overrides);
}

const AUTH_CARD = ['number' => '4000 0025 0000 3155', 'exp' => '12/30', 'cvc' => '123', 'name' => 'Tess', 'country' => 'AU'];

describe('price at payment', function () {
    it('charges the current price, not a stale saved total', function () {
        $user = businessUser();
        $campaign = readyCampaign($this, $user);
        $correct = $campaign->total_cents;
        $campaign->forceFill(['total_cents' => 100])->save(); // e.g. saved before a rate card change

        $this->post("/app/campaigns/{$campaign->id}/pay", campaignPay())->assertRedirect("/app/jobs/campaigns/{$campaign->id}");

        expect($campaign->payments()->where('status', 'succeeded')->sole()->amount_cents)->toBe($correct);
    });

    it('stops payment when the promo code is no longer valid', function () {
        $user = businessUser();
        $campaign = readyCampaign($this, $user);
        $promo = PromoCode::create(['code' => 'SPRING'.uniqid(), 'type' => 'percent', 'value' => 20, 'service' => 'any', 'min_subtotal_cents' => 0, 'is_active' => true, 'uses' => 0]);
        $this->patchJson("/app/campaigns/{$campaign->id}", ['promo_code' => $promo->code])->assertSuccessful();
        $promo->update(['is_active' => false]);

        $this->post("/app/campaigns/{$campaign->id}/pay", campaignPay())->assertSessionHasErrors('promo_code');

        expect($campaign->fresh()->status)->toBe('draft')->and($campaign->payments()->count())->toBe(0);
    });

    it('asks the customer to review when the price changed since the page loaded', function () {
        $user = businessUser();
        $campaign = readyCampaign($this, $user);

        $this->post("/app/campaigns/{$campaign->id}/pay", campaignPay(['expected_total_cents' => $campaign->total_cents - 500]))
            ->assertSessionHasErrors('payment');

        expect($campaign->payments()->count())->toBe(0);
    });
});

describe('a draft with a payment waiting on 3-D Secure', function () {
    it('cancels the open payment when the customer edits the draft', function () {
        $user = businessUser();
        $campaign = readyCampaign($this, $user);
        $this->post("/app/campaigns/{$campaign->id}/pay", campaignPay(['card' => AUTH_CARD]))->assertSessionHas('payment_action');
        $payment = $campaign->payments()->sole();

        $this->patchJson("/app/campaigns/{$campaign->id}", ['notes' => 'Changed my mind'])->assertSuccessful();

        expect($payment->fresh()->status)->toBe('failed')
            ->and($payment->fresh()->failure_reason)->toContain('changed');
        $this->post(route('app.payments.confirm', $payment))->assertSessionHasErrors('payment');
        expect($campaign->fresh()->status)->toBe('draft');
    });

    it('cancels the open payment before deleting the draft', function () {
        $user = businessUser();
        $campaign = readyCampaign($this, $user);
        $this->post("/app/campaigns/{$campaign->id}/pay", campaignPay(['card' => AUTH_CARD]));
        $payment = $campaign->payments()->sole();

        $this->delete("/app/campaigns/{$campaign->id}")->assertRedirect();

        expect(Campaign::find($campaign->id))->toBeNull()->and($payment->fresh()?->status)->not->toBe('requires_action');
    });

    it('completes the order instead of editing when the bank already approved it', function () {
        $user = businessUser();
        $campaign = readyCampaign($this, $user);
        config(['epfd.payments.gateway' => 'stripe', 'epfd.payments.stripe_key' => 'pk_test_x', 'epfd.payments.stripe_secret' => 'sk_test_x', 'epfd.payments.stripe_webhook_secret' => 'whsec_x']);
        app(PaymentGatewayManager::class)->refresh();
        Http::fake([
            'api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_open', 'status' => 'requires_action', 'client_secret' => 'pi_open_secret']),
            'api.stripe.com/v1/payment_intents/pi_open/cancel' => Http::response(['error' => ['message' => 'This PaymentIntent cannot be canceled because it has a status of succeeded.']], 400),
            'api.stripe.com/v1/payment_intents/pi_open*' => Http::response(['id' => 'pi_open', 'status' => 'succeeded']),
        ]);
        $this->post("/app/campaigns/{$campaign->id}/pay", campaignPay(['card' => null, 'payment_method' => 'pm_card_threeDSecure2Required']))->assertSessionHas('payment_action');

        $this->patchJson("/app/campaigns/{$campaign->id}", ['notes' => 'Too late'])->assertStatus(409);

        expect($campaign->fresh()->status)->toBe('submitted')
            ->and($campaign->payments()->sole()->status)->toBe('succeeded');
    });
});
