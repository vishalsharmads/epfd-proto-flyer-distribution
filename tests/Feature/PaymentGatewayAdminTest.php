<?php

declare(strict_types=1);

use App\Models\PaymentGatewayConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('saves gateway keys encrypted and never sends secrets back to the page', function () {
    $admin = staffUser('super_admin');

    $this->actingAs($admin)->put('/admin/settings/payment-gateways/stripe', [
        'mode' => 'test',
        'credentials' => ['publishable_key' => 'pk_test_abc', 'secret_key' => 'sk_test_secret', 'webhook_secret' => 'whsec_secret'],
    ])->assertRedirect()->assertSessionHas('success');

    expect(DB::table('payment_gateways')->where('driver', 'stripe')->value('credentials'))->not->toContain('sk_test_secret')
        ->and(PaymentGatewayConfig::find('stripe')->credentials['secret_key'])->toBe('sk_test_secret');

    $this->get('/admin/settings/payment-gateways')->assertOk()
        ->assertDontSee('sk_test_secret')->assertDontSee('whsec_secret')
        ->assertInertia(fn ($page) => $page->where('gateways.1.fields.0.value', 'pk_test_abc')->where('gateways.1.fields.1.set', true)->where('gateways.1.fields.1.value', null));
});

it('keeps a saved secret when the field is left blank', function () {
    $admin = staffUser('super_admin');
    $this->actingAs($admin)->put('/admin/settings/payment-gateways/stripe', ['mode' => 'test', 'credentials' => ['publishable_key' => 'pk_test_1', 'secret_key' => 'sk_test_keep', 'webhook_secret' => 'whsec_keep']]);

    $this->put('/admin/settings/payment-gateways/stripe', ['mode' => 'test', 'credentials' => ['publishable_key' => 'pk_test_2', 'secret_key' => '', 'webhook_secret' => '']]);

    expect(PaymentGatewayConfig::find('stripe')->credentials)->toMatchArray(['publishable_key' => 'pk_test_2', 'secret_key' => 'sk_test_keep', 'webhook_secret' => 'whsec_keep']);
});

it('refuses to switch to a gateway whose keys do not work', function () {
    $admin = staffUser('super_admin');
    config(['epfd.payments.stripe_key' => null, 'epfd.payments.stripe_secret' => null, 'epfd.payments.stripe_webhook_secret' => null]);

    $this->actingAs($admin)->put('/admin/settings', ['values' => ['payments' => ['gateway' => 'stripe']]])
        ->assertSessionHasErrors('values.payments.gateway');

    $this->put('/admin/settings/payment-gateways/stripe', ['mode' => 'test', 'credentials' => ['publishable_key' => 'pk_test_1', 'secret_key' => 'sk_test_bad', 'webhook_secret' => 'whsec_1']]);
    Http::fake(['api.stripe.com/v1/balance' => Http::response(['error' => ['message' => 'Invalid API Key provided']], 401)]);

    $this->put('/admin/settings', ['values' => ['payments' => ['gateway' => 'stripe']]])
        ->assertSessionHasErrors(['values.payments.gateway' => 'Invalid API Key provided']);
});

it('switches gateway once the connection test passes', function () {
    $admin = staffUser('super_admin');
    $this->actingAs($admin)->put('/admin/settings/payment-gateways/stripe', ['mode' => 'test', 'credentials' => ['publishable_key' => 'pk_test_1', 'secret_key' => 'sk_test_ok', 'webhook_secret' => 'whsec_1']]);
    Http::fake(['api.stripe.com/v1/balance' => Http::response(['object' => 'balance'])]);

    $this->post('/admin/settings/payment-gateways/stripe/test')->assertSessionHas('success');
    $this->put('/admin/settings', ['values' => ['payments' => ['gateway' => 'stripe']]])->assertSessionHasNoErrors();

    expect(config('epfd.payments.gateway'))->toBe('stripe');
});

it('warns when a live key is used in test mode', function () {
    $admin = staffUser('super_admin');
    $this->actingAs($admin)->put('/admin/settings/payment-gateways/stripe', ['mode' => 'test', 'credentials' => ['publishable_key' => 'pk_live_1', 'secret_key' => 'sk_live_x', 'webhook_secret' => 'whsec_1']]);
    Http::fake(['api.stripe.com/v1/balance' => Http::response(['object' => 'balance'])]);

    $this->post('/admin/settings/payment-gateways/stripe/test')->assertSessionHas('error', 'This is a live key but the gateway is in test mode.');
});

it('is limited to staff who manage settings', function () {
    $this->actingAs(staffUser('support'))->get('/admin/settings/payment-gateways')->assertForbidden();
});
