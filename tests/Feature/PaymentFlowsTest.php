<?php

declare(strict_types=1);

use App\Domain\Payments\CheckoutService;
use App\Domain\Payments\PaymentActionRequired;
use App\Domain\Payments\PaymentGatewayManager;
use App\Models\DesignOrder;
use App\Models\Payment;
use App\Models\SavedCard;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
});

/** A design order with a complete brief, ready to pay. */
function payableDesignOrder($test, User $user): DesignOrder
{
    $test->actingAs($user)->post('/app/design', ['design_type' => 'flyer'])->assertRedirect();
    $order = DesignOrder::where('business_id', $user->business_id)->latest('id')->firstOrFail();
    $test->patchJson("/app/design/{$order->id}", ['package' => 'basic', 'headline' => 'Grand opening', 'key_message' => 'Free coffee for the first 100'])->assertOk();

    return $order->fresh();
}

function checkoutBody(array $overrides = []): array
{
    return array_replace([
        'billing' => ['company' => 'Test Biz', 'abn' => null, 'address' => '257 Victoria Road, Rydalmere NSW 2116'],
        'save_card' => false,
        'use_credits' => false,
        'accept_terms' => true,
    ], $overrides);
}

function useStripe(): void
{
    config(['epfd.payments.gateway' => 'stripe', 'epfd.payments.stripe_key' => 'pk_test_x', 'epfd.payments.stripe_secret' => 'sk_test_x', 'epfd.payments.stripe_webhook_secret' => 'whsec_test']);
    app(PaymentGatewayManager::class)->refresh();
}

function stripeSignature(string $payload, ?int $at = null): string
{
    $at ??= time();

    return "t={$at},v1=".hash_hmac('sha256', "{$at}.{$payload}", 'whsec_test');
}

describe('3-D Secure', function () {
    it('waits for authentication, then completes the order on confirm', function () {
        $user = businessUser();
        $order = payableDesignOrder($this, $user);
        $card = ['number' => '4000 0025 0000 3155', 'exp' => '12/30', 'cvc' => '123', 'name' => 'Tess', 'country' => 'AU'];

        $this->post("/app/design/{$order->id}/pay", checkoutBody(['card' => $card, 'save_card' => true]))
            ->assertRedirect()->assertSessionHas('payment_action.client_secret');

        $payment = Payment::where('payable_id', $order->id)->where('payable_type', 'design_order')->sole();
        expect($payment->status)->toBe('requires_action')
            ->and($order->fresh()->status)->toBe('draft')
            ->and(json_encode($payment->meta))->not->toContain('4000002500003155');

        $this->post(route('app.payments.confirm', $payment))->assertRedirect("/app/jobs/design/{$order->id}");

        expect($payment->fresh()->status)->toBe('succeeded')
            ->and($order->fresh()->status)->toBe('submitted')
            ->and($payment->fresh()->invoice)->not->toBeNull()
            ->and(SavedCard::where('business_id', $user->business_id)->where('last4', '3155')->exists())->toBeTrue();
    });

    it('lets the gateway webhook complete the order when the customer closes the tab', function () {
        $user = businessUser();
        $order = payableDesignOrder($this, $user);
        $card = ['number' => '4000 0025 0000 3155', 'exp' => '12/30', 'cvc' => '123', 'name' => 'Tess', 'country' => 'AU'];
        $this->post("/app/design/{$order->id}/pay", checkoutBody(['card' => $card]))->assertSessionHas('payment_action');
        $payment = Payment::where('payable_id', $order->id)->where('payable_type', 'design_order')->sole();

        $body = json_encode(['id' => 'evt_'.uniqid(), 'type' => 'payment.succeeded', 'data' => ['gateway_ref' => $payment->gateway_ref]]);
        $this->call('POST', '/api/v1/webhooks/mock', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_EPFD_SIGNATURE' => hash_hmac('sha256', $body, 'mock-webhook-secret'),
        ], $body)->assertOk()->assertJsonPath('status', 'processed');

        expect($order->fresh()->status)->toBe('submitted')->and($payment->fresh()->invoice)->not->toBeNull();
    });

    it('stops other businesses confirming a payment', function () {
        $user = businessUser();
        $order = payableDesignOrder($this, $user);
        $card = ['number' => '4000 0025 0000 3155', 'exp' => '12/30', 'cvc' => '123', 'name' => 'Tess', 'country' => 'AU'];
        $this->post("/app/design/{$order->id}/pay", checkoutBody(['card' => $card]));
        $payment = Payment::where('payable_id', $order->id)->where('payable_type', 'design_order')->sole();

        $this->actingAs(businessUser())->post(route('app.payments.confirm', $payment))->assertForbidden();
    });
});

describe('Stripe', function () {
    it('refuses raw card numbers once Stripe is active', function () {
        $user = businessUser();
        $order = payableDesignOrder($this, $user);
        useStripe();

        $this->post("/app/design/{$order->id}/pay", checkoutBody(['card' => ['number' => '4242 4242 4242 4242', 'exp' => '12/30', 'cvc' => '123', 'name' => 'T']]))
            ->assertSessionHasErrors('card.number');
        expect($order->fresh()->status)->toBe('draft');
    });

    it('charges a browser-tokenised card and saves it to the business customer', function () {
        $user = businessUser();
        $order = payableDesignOrder($this, $user);
        useStripe();
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_123']),
            'api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_123', 'status' => 'succeeded', 'latest_charge' => ['payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242']]]]),
            'api.stripe.com/v1/payment_methods/*' => Http::response(['card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 4, 'exp_year' => 2031]]),
        ]);

        $this->post("/app/design/{$order->id}/pay", checkoutBody(['payment_method' => 'pm_card_visa', 'save_card' => true]))
            ->assertRedirect("/app/jobs/design/{$order->id}");

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/payment_intents')
            && $r['payment_method'] === 'pm_card_visa' && $r['customer'] === 'cus_123' && $r['setup_future_usage'] === 'off_session'
            && $r->hasHeader('Idempotency-Key'));
        // Pinned to the latest API version, which rejects the removed payment_method_types parameter.
        Http::assertSent(fn (HttpRequest $r) => $r->header('Stripe-Version') === ['2026-09-30.endive']);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains(urldecode($r->body()), '&payment_method_types'));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/payment_intents')
            && str_contains(urldecode($r->body()), 'allowed_payment_method_types[]=card'));
        $payment = Payment::where('payable_id', $order->id)->where('payable_type', 'design_order')->sole();
        expect($payment->gateway)->toBe('stripe')->and($payment->gateway_ref)->toBe('pi_123')->and($payment->card_last4)->toBe('4242')
            ->and(SavedCard::where('business_id', $user->business_id)->where('gateway', 'stripe')->value('exp_year'))->toBe(2031)
            ->and($user->business->fresh()->gateway_customers)->toBe(['stripe_test' => 'cus_123']);
    });

    it('asks for 3-D Secure and completes after the browser authenticates', function () {
        $user = businessUser();
        $order = payableDesignOrder($this, $user);
        useStripe();
        Http::fake([
            'api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_3ds', 'status' => 'requires_action', 'client_secret' => 'pi_3ds_secret_x']),
            'api.stripe.com/v1/payment_intents/pi_3ds*' => Http::response(['id' => 'pi_3ds', 'status' => 'succeeded', 'latest_charge' => ['payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '3155']]]]),
        ]);

        try {
            app(CheckoutService::class)->pay($order, $user, checkoutBody(['payment_method' => 'pm_card_threeDSecure2Required']));
            $this->fail('Expected PaymentActionRequired');
        } catch (PaymentActionRequired $e) {
            expect($e->extra['client_secret'])->toBe('pi_3ds_secret_x');
            $payment = $e->payment;
        }

        app(CheckoutService::class)->confirm($payment);

        expect($payment->fresh()->status)->toBe('succeeded')->and($payment->fresh()->card_last4)->toBe('3155')
            ->and($order->fresh()->status)->toBe('submitted');
    });

    it('records a decline without submitting the order', function () {
        $user = businessUser();
        $order = payableDesignOrder($this, $user);
        useStripe();
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['error' => ['message' => 'Your card was declined.', 'payment_intent' => ['id' => 'pi_no', 'status' => 'requires_payment_method']]], 402)]);

        $this->post("/app/design/{$order->id}/pay", checkoutBody(['payment_method' => 'pm_card_chargeDeclined']))
            ->assertSessionHasErrors(['payment' => 'Your card was declined.']);
        expect($order->fresh()->status)->toBe('draft');
    });

    it('accepts a fresh signed webhook and rejects a replayed old one', function () {
        useStripe();
        $payload = json_encode(['id' => 'evt_'.uniqid(), 'type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_x']]]);

        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload)], $payload)
            ->assertOk()->assertJsonPath('status', 'ignored');
        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload, time() - 3600)], $payload)
            ->assertStatus(400);
    });
});

describe('switching gateways', function () {
    it('refunds through the gateway that took the payment', function () {
        $user = businessUser();
        $order = payableDesignOrder($this, $user);
        $this->post("/app/design/{$order->id}/pay", checkoutBody(['card' => ['number' => '4242 4242 4242 4242', 'exp' => '12/30', 'cvc' => '123', 'name' => 'T', 'country' => 'AU']]));
        $payment = Payment::where('payable_id', $order->id)->where('payable_type', 'design_order')->where('status', 'succeeded')->sole();
        expect($payment->gateway)->toBe('mock');

        useStripe();
        Http::fake();
        app(CheckoutService::class)->refund($payment, 500, staffUser('finance'), 'Goodwill');

        Http::assertNothingSent();
        expect($payment->fresh()->refunded_cents)->toBe(500);
    });

    it('only offers saved cards from the active gateway', function () {
        $user = businessUser();
        SavedCard::create(['business_id' => $user->business_id, 'gateway' => 'mock', 'gateway_token' => 'mock_tok_x', 'brand' => 'visa', 'last4' => '1111', 'exp_month' => 1, 'exp_year' => 2035, 'holder_name' => 'T', 'is_default' => true]);
        $order = payableDesignOrder($this, $user);
        useStripe();

        $this->get("/app/design/{$order->id}/confirm")->assertOk()
            ->assertInertia(fn ($page) => $page->where('payment.saved_cards', [])->where('payment.gateway.driver', 'stripe')->where('payment.gateway.publishable_key', 'pk_test_x'));
    });
});
