<?php

declare(strict_types=1);

use App\Domain\Payments\MockGateway;
use App\Domain\Payments\PaymentGatewayManager;
use App\Domain\Payments\StripeGateway;

function freshGatewayManager(): PaymentGatewayManager
{
    return new PaymentGatewayManager(app());
}

describe('payment gateway resolution', function () {
    it('uses the mock gateway outside production', function () {
        expect(freshGatewayManager()->driver('mock'))->toBeInstanceOf(MockGateway::class);
    });

    it('refuses the mock gateway in production', function () {
        app()->detectEnvironment(fn () => 'production');

        freshGatewayManager()->driver('mock');
    })->throws(RuntimeException::class, 'cannot be used in production');

    it('refuses Stripe without credentials in production', function () {
        app()->detectEnvironment(fn () => 'production');
        config(['epfd.payments.stripe_secret' => null]);

        freshGatewayManager()->driver('stripe');
    })->throws(RuntimeException::class, 'needs a publishable key, secret key and webhook secret');

    it('falls back to mock for unconfigured Stripe outside production', function () {
        config(['epfd.payments.stripe_secret' => null]);

        expect(freshGatewayManager()->driver('stripe'))->toBeInstanceOf(MockGateway::class);
    });

    it('uses Stripe when it is configured', function () {
        config(['epfd.payments.stripe_key' => 'pk_test_x', 'epfd.payments.stripe_secret' => 'sk_test_x', 'epfd.payments.stripe_webhook_secret' => 'whsec_x']);

        expect(freshGatewayManager()->driver('stripe'))->toBeInstanceOf(StripeGateway::class);
    });
});

describe('mock webhooks', function () {
    it('rejects mock webhooks in production', function () {
        app()->detectEnvironment(fn () => 'production');
        app()->forgetInstance(PaymentGatewayManager::class);
        $body = json_encode(['id' => 'evt_1', 'type' => 'payment.succeeded', 'data' => ['gateway_ref' => 'x']]);

        $this->call('POST', '/api/v1/webhooks/mock', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EPFD_SIGNATURE' => hash_hmac('sha256', $body, 'mock-webhook-secret'),
        ], $body)->assertNotFound();
    });

    it('rejects every signature when no mock secret is configured', function () {
        config(['epfd.payments.mock_webhook_secret' => null]);
        $body = json_encode(['id' => 'evt_2', 'type' => 'payment.succeeded']);

        $this->call('POST', '/api/v1/webhooks/mock', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EPFD_SIGNATURE' => hash_hmac('sha256', $body, ''),
        ], $body)->assertStatus(400);
    });

    it('accepts a correctly signed mock webhook outside production', function () {
        $body = json_encode(['id' => 'evt_3', 'type' => 'something.else']);

        $this->call('POST', '/api/v1/webhooks/mock', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EPFD_SIGNATURE' => hash_hmac('sha256', $body, 'mock-webhook-secret'),
        ], $body)->assertOk();
    });
});
