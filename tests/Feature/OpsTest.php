<?php

declare(strict_types=1);

use App\Models\DesignOrder;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

it('reports every check as ok when the app is healthy', function () {
    $this->getJson('/health')->assertOk()
        ->assertJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'cache' => 'ok', 'storage' => 'ok', 'queue' => 'ok', 'payments' => 'ok']])
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('answers 503 when the payment gateway cannot be used', function () {
    app()->detectEnvironment(fn () => 'production'); // mock gateway is refused in production

    $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.payments', 'fail')->assertJsonPath('checks.database', 'ok');
});

it('answers 503 when queued jobs are stuck', function () {
    config(['queue.default' => 'database']);
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subHour()->timestamp, 'created_at' => now()->subHour()->timestamp]);

    $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.queue', 'fail');
});

it('closes payments that never finished 3-D Secure', function () {
    $user = businessUser();
    $old = Payment::factory()->create(['business_id' => $user->business_id, 'status' => 'requires_action', 'created_at' => now()->subDays(2)]);
    $recent = Payment::factory()->create(['business_id' => $user->business_id, 'status' => 'requires_action']);

    $this->artisan('payments:expire-abandoned')->assertSuccessful();

    expect($old->fresh()->status)->toBe('failed')->and($recent->fresh()->status)->toBe('requires_action');
});

it('still completes an expired payment if the gateway later reports success', function () {
    $user = businessUser();
    $this->actingAs($user)->post('/app/design', ['design_type' => 'flyer']);
    $order = DesignOrder::where('business_id', $user->business_id)->latest('id')->firstOrFail();
    $this->patchJson("/app/design/{$order->id}", ['package' => 'basic', 'headline' => 'Late', 'key_message' => 'Authenticated after expiry']);
    $this->post("/app/design/{$order->id}/pay", [
        'billing' => ['company' => 'Biz', 'address' => '257 Victoria Road, Rydalmere NSW 2116'], 'accept_terms' => true,
        'card' => ['number' => '4000 0025 0000 3155', 'exp' => '12/30', 'cvc' => '123', 'name' => 'T', 'country' => 'AU'],
    ]);
    $payment = Payment::where('payable_id', $order->id)->where('payable_type', 'design_order')->sole();
    $payment->forceFill(['created_at' => now()->subDays(2)])->save();
    $this->artisan('payments:expire-abandoned');

    $body = json_encode(['id' => 'evt_'.uniqid(), 'type' => 'payment.succeeded', 'data' => ['gateway_ref' => $payment->gateway_ref]]);
    $this->call('POST', '/api/v1/webhooks/mock', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_EPFD_SIGNATURE' => hash_hmac('sha256', $body, 'mock-webhook-secret')], $body)
        ->assertJsonPath('status', 'processed');

    expect($payment->fresh()->status)->toBe('succeeded')->and($order->fresh()->status)->toBe('submitted');
});
