<?php

declare(strict_types=1);

use App\Domain\Pricing\PricingFactory;
use App\Domain\Pricing\RateCardDefaults;
use App\Mail\DesignProofReadyMail;
use App\Mail\InvoiceMail;
use App\Models\AuditLog;
use App\Models\DesignOrder;
use App\Models\PrintOrder;
use App\Models\PrintProduct;
use App\Models\RateCard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function designWebPay(): array
{
    return [
        'billing' => ['company' => 'Test Biz', 'abn' => null, 'address' => '257 Victoria Road, Rydalmere NSW 2116'],
        'card' => ['number' => '4242 4242 4242 4242', 'exp' => '12/30', 'cvc' => '123', 'name' => 'Tess Tester', 'country' => 'AU'],
        'save_card' => false,
        'use_credits' => false,
        'accept_terms' => true,
    ];
}

/** A paid design order (submitted) for the given business user, built through the web endpoints. */
function paidDesignOrder($test, $user, array $service = ['package' => 'basic']): DesignOrder
{
    $test->actingAs($user)->post('/app/design', ['design_type' => 'flyer'])->assertRedirect();
    $order = DesignOrder::where('business_id', $user->business_id)->latest('id')->firstOrFail();
    $test->patchJson("/app/design/{$order->id}", $service + ['headline' => 'Grand opening', 'key_message' => 'Free coffee for the first 100 customers'])->assertOk();
    $test->post("/app/design/{$order->id}/pay", designWebPay())->assertRedirect("/app/jobs/design/{$order->id}");

    return $order->fresh();
}

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
});

describe('design studio (web)', function () {
    it('runs wizard → pay → proof → changes → approve → print this design', function () {
        $user = businessUser();
        $this->actingAs($user);

        // Start: a draft lands on the Service step.
        $this->post('/app/design', ['design_type' => 'flyer'])->assertRedirect();
        $order = DesignOrder::where('business_id', $user->business_id)->latest('id')->firstOrFail();
        expect($order->status)->toBe('draft')->and($order->reference)->toStartWith('DSN-');
        $this->get("/app/design/{$order->id}/service")->assertOk();

        // Service: basic, double-sided (+40%), stock imagery add-on → (99 × 1.4) + 25 = $163.60 ex GST.
        $res = $this->patchJson("/app/design/{$order->id}", ['package' => 'basic', 'sides' => 2, 'addons' => ['stock_imagery']])->assertOk();
        expect($res->json('data.subtotal_cents'))->toBe(16360)->and($res->json('data.gst_cents'))->toBe(1636)
            ->and($res->json('data.revisions_included'))->toBe(1);
        $this->patchJson("/app/design/{$order->id}", ['package' => 'gold'])->assertStatus(422);

        // Brief + reference uploads (PDF/PNG/JPG/SVG only).
        $this->patchJson("/app/design/{$order->id}", ['headline' => 'Fresh coffee, five minutes from home', 'key_message' => 'Grand opening Saturday', 'due_date' => now()->addWeek()->toDateString()])->assertOk();
        $this->post("/app/design/{$order->id}/assets", ['files' => [UploadedFile::fake()->image('logo.png', 400, 400), UploadedFile::fake()->create('brand.pdf', 120, 'application/pdf')]], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonCount(2, 'data');
        $this->post("/app/design/{$order->id}/assets", ['files' => [UploadedFile::fake()->create('evil.exe', 10, 'application/x-msdownload')]], ['Accept' => 'application/json'])->assertStatus(422);
        expect($order->assets()->where('kind', 'brief_upload')->count())->toBe(2);

        // Confirm & pay.
        $this->get("/app/design/{$order->id}/confirm")->assertOk();
        $this->post("/app/design/{$order->id}/pay", designWebPay())->assertRedirect("/app/jobs/design/{$order->id}");
        $order->refresh();
        expect($order->status)->toBe('submitted')->and($order->paid_at)->not->toBeNull()
            ->and($order->payments()->where('status', 'succeeded')->value('payable_type'))->toBe('design_order')
            ->and($order->invoice->total_cents)->toBe(17996);
        Mail::assertQueued(InvoiceMail::class);
        $this->get("/app/jobs/design/{$order->id}")->assertOk();
        $this->get('/app/jobs')->assertOk()->assertSee('DSN-');

        // Admin (operations): assign, start, upload proof v1 → proof_ready + email.
        $ops = staffUser('operations');
        $this->actingAs($ops);
        $this->get('/admin/design-orders')->assertOk();
        $this->get("/admin/design-orders/{$order->id}")->assertOk();
        $this->post("/admin/design-orders/{$order->id}/assign", ['designer_id' => $ops->id])->assertSessionHas('success');
        $this->post("/admin/design-orders/{$order->id}/status", ['status' => 'in_design'])->assertSessionHas('success');
        $this->post("/admin/design-orders/{$order->id}/proofs", ['file' => UploadedFile::fake()->image('proof.png', 620, 874), 'note' => 'First concept'])->assertSessionHas('success');
        expect($order->fresh()->status)->toBe('proof_ready')->and($order->fresh()->latestProofVersion())->toBe(1);
        Mail::assertQueued(DesignProofReadyMail::class, fn ($m) => $m->order->is($order) && $m->proof->version === 1);

        // Business: request changes (uses the one included revision).
        $this->actingAs($user);
        $this->post("/app/design/{$order->id}/request-changes", ['changes' => 'Make the logo bigger please.'])->assertSessionHas('success');
        $order->refresh();
        expect($order->status)->toBe('changes_requested')->and($order->revisions_used)->toBe(1)->and($order->extraRevisions())->toBe(0)
            ->and($order->thread->messages()->count())->toBe(1);
        $this->post("/app/design/{$order->id}/approve")->assertSessionHas('error'); // nothing to approve yet

        // Proof v2 → second change request is beyond the package: accepted, flagged as an extra (invoiced) revision.
        $this->actingAs($ops)->post("/admin/design-orders/{$order->id}/proofs", ['file' => UploadedFile::fake()->image('proof2.png', 620, 874)])->assertSessionHas('success');
        $this->actingAs($user)->post("/app/design/{$order->id}/request-changes", ['changes' => 'Swap the photo for the shopfront.'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'extra revision'));
        expect($order->fresh()->extraRevisions())->toBe(1);

        // Proof v3 → approve.
        $this->actingAs($ops)->post("/admin/design-orders/{$order->id}/proofs", ['file' => UploadedFile::fake()->image('proof3.png', 620, 874)])->assertSessionHas('success');
        $this->actingAs($user)->post("/app/design/{$order->id}/approve")->assertSessionHas('success');
        expect($order->fresh()->status)->toBe('approved')->and($order->fresh()->approved_at)->not->toBeNull();

        // Print + distribute: print-order draft with the matching product and the approved proof as front artwork.
        $res = $this->post("/app/design/{$order->id}/print", ['also_distribute' => true]);
        $print = PrintOrder::where('business_id', $user->business_id)->latest('id')->firstOrFail();
        $res->assertRedirect("/app/print/{$print->id}/specs");
        expect($print->status)->toBe('draft')->and($print->product->slug)->toBe('a5-flyers')->and($print->also_distribute)->toBeTrue()
            ->and($print->artworks()->count())->toBe(1)->and($print->artworks()->value('side'))->toBe('front')
            ->and($print->total_cents)->toBeGreaterThan(0)
            ->and($order->fresh()->print_order_id)->toBe($print->id);
        Storage::disk('local')->assertExists($print->artworks()->value('path'));

        // Admin: final files + complete.
        $this->actingAs($ops);
        $this->post("/admin/design-orders/{$order->id}/status", ['status' => 'completed'])->assertSessionHas('error'); // needs final files
        $this->post("/admin/design-orders/{$order->id}/finals", ['files' => [UploadedFile::fake()->create('final.pdf', 200, 'application/pdf')]])->assertSessionHas('success');
        $this->post("/admin/design-orders/{$order->id}/status", ['status' => 'completed'])->assertSessionHas('success');
        expect($order->fresh()->status)->toBe('completed');

        $actions = AuditLog::where('auditable_type', 'design_order')->where('auditable_id', $order->id)->pluck('action');
        expect($actions)->toContain('design_order.designer_assigned', 'design_order.proof_uploaded', 'design_order.changes_requested', 'design_order.approved', 'design_order.print_order_created', 'design_order.final_uploaded', 'design_order.status_changed');
    });

    it('preselects the print product when started from the print wizard', function () {
        $user = businessUser();
        $product = PrintProduct::where('slug', 'business-cards')->firstOrFail();
        $this->actingAs($user)->post('/app/design', ['print_product_id' => $product->id])->assertRedirect();
        $order = DesignOrder::where('business_id', $user->business_id)->latest('id')->firstOrFail();
        expect($order->design_type)->toBe('business_card')->and($order->print_product_id)->toBe($product->id)->and($order->size)->toBe($product->size_label);
    });

    it('requires a brief before payment and keeps other businesses out', function () {
        $user = businessUser();
        $this->actingAs($user)->post('/app/design');
        $order = DesignOrder::where('business_id', $user->business_id)->latest('id')->firstOrFail();
        $this->post("/app/design/{$order->id}/pay", designWebPay())->assertSessionHasErrors('headline');
        expect($order->fresh()->status)->toBe('draft');

        $this->actingAs(businessUser())->get("/app/design/{$order->id}/service")->assertForbidden();
        $this->patchJson("/app/design/{$order->id}", ['package' => 'premium'])->assertForbidden();
    });
});

describe('design studio RBAC', function () {
    it('lets support view but not manage; finance cannot see design orders', function () {
        $order = paidDesignOrder($this, businessUser());

        $support = staffUser('support');
        $this->actingAs($support)->get('/admin/design-orders')->assertOk();
        $this->get("/admin/design-orders/{$order->id}")->assertOk();
        $this->post("/admin/design-orders/{$order->id}/proofs", ['file' => UploadedFile::fake()->image('p.png')])->assertForbidden();
        $this->post("/admin/design-orders/{$order->id}/status", ['status' => 'in_design'])->assertForbidden();
        expect($order->fresh()->status)->toBe('submitted');

        $this->actingAs(staffUser('finance'))->get('/admin/design-orders')->assertForbidden();
        $this->actingAs(staffUser('operations'))->post("/admin/design-orders/{$order->id}/status", ['status' => 'in_design'])->assertSessionHas('success');
    });

    it('enforces permissions on the admin API', function () {
        $order = paidDesignOrder($this, businessUser());
        Sanctum::actingAs(staffUser('support'));
        $this->getJson('/api/v1/admin/design-orders')->assertOk();
        $this->patchJson("/api/v1/admin/design-orders/{$order->id}/status", ['status' => 'in_design'])->assertForbidden();
    });
});

describe('design studio API', function () {
    it('lists services and quotes publicly', function () {
        $this->getJson('/api/v1/design/services')->assertOk()->assertJsonCount(5, 'data.types')->assertJsonPath('data.types.0.prices.basic', 9900);
        $q = $this->postJson('/api/v1/pricing/design/quote', ['design_type' => 'flyer', 'package' => 'standard', 'sides' => 2, 'addons' => ['rush']])->assertOk()->json('data');
        // 179 × 1.4 = 250.60; rush +50% = 125.30 → 375.90 ex GST.
        expect($q['service'])->toBe('design')->and($q['subtotal_cents'])->toBe(37590)->and($q['total_cents'])->toBe($q['subtotal_cents'] + $q['gst_cents'])
            ->and($q['meta']['turnaround_days'])->toBe(2);
        $this->postJson('/api/v1/pricing/design/quote', ['design_type' => 'logo', 'package' => 'basic'])->assertStatus(422);
    });

    it('creates, uploads, pays, proofs and approves through the API', function () {
        Sanctum::actingAs(businessUser());
        $order = $this->postJson('/api/v1/design/orders', ['design_type' => 'brochure', 'package' => 'standard', 'headline' => 'Autumn menu', 'key_message' => 'New seasonal dishes'])
            ->assertCreated()->json('data');
        expect($order['status'])->toBe('draft')->and($order['subtotal_cents'])->toBe(39900);
        $this->patchJson("/api/v1/design/orders/{$order['id']}", ['addons' => ['source_files']])->assertOk()->assertJsonPath('data.subtotal_cents', 44800);
        $this->post("/api/v1/design/orders/{$order['id']}/assets", ['files' => [UploadedFile::fake()->image('ref.jpg')]], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson("/api/v1/design/orders/{$order['id']}/pay", [
            'card' => ['number' => '4242424242424242', 'exp_month' => 12, 'exp_year' => 2030, 'cvc' => '123', 'name' => 'T'],
            'billing' => ['company' => 'X', 'address' => 'Y'], 'accept_terms' => true,
        ], ['Idempotency-Key' => 'd-'.uniqid()])->assertCreated()->assertJsonPath('data.design_order.status', 'submitted');

        Sanctum::actingAs(staffUser('operations'));
        $this->post("/api/v1/admin/design-orders/{$order['id']}/proofs", ['file' => UploadedFile::fake()->image('proof.png')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.design_order.status', 'proof_ready');

        Sanctum::actingAs(DesignOrder::find($order['id'])->user);
        $this->postJson("/api/v1/design/orders/{$order['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/design/orders/{$order['id']}/approve")->assertStatus(409);
    });
});

describe('design rate card', function () {
    it('saves edited design prices from the admin editor and previews service=design', function () {
        $this->actingAs(staffUser('finance'));
        $card = PricingFactory::rateCard();
        $card['design']['types']['flyer']['prices']['basic'] = 109;

        $this->postJson('/admin/rate-card/preview', ['data' => $card, 'input' => ['service' => 'design', 'design_type' => 'flyer', 'package' => 'basic', 'sides' => 1, 'addons' => []]])
            ->assertOk()->assertJsonPath('quote.subtotal_cents', 10900);

        $this->post('/admin/rate-card', ['name' => 'Design test', 'data' => $card])->assertSessionHas('success');
        expect(PricingFactory::rateCard()['design']['types']['flyer']['prices']['basic'])->toEqual(109.0)
            ->and(PricingFactory::rateCard()['design']['types']['flyer']['sides_option'])->toBeTrue();
        $this->postJson('/api/v1/pricing/design/quote', ['design_type' => 'flyer', 'package' => 'basic'])->assertJsonPath('data.subtotal_cents', 10900);
    });

    it('falls back to default design prices for rate cards saved before Design Studio', function () {
        $card = RateCardDefaults::data();
        unset($card['design']);
        RateCard::where('is_active', true)->update(['is_active' => false]);
        RateCard::create(['name' => 'Legacy', 'is_active' => true, 'data' => $card]);
        $this->postJson('/api/v1/pricing/design/quote', ['design_type' => 'stationery', 'package' => 'premium'])->assertOk()->assertJsonPath('data.subtotal_cents', 54900);
    });
});

it('survives the queue: the invoice email serialises, renders and attaches the PDF', function () {
    $user = businessUser();
    $order = paidDesignOrder($this, $user);
    $invoice = $order->payments()->firstOrFail()->invoice;

    // What a queue worker does: serialise the mailable (models become identifiers), then restore it.
    $mail = unserialize(serialize(new InvoiceMail($invoice, $user->name, 'Design order', url('/'))));
    $pdf = $mail->attachments()[0];

    expect($mail->invoice->is($invoice))->toBeTrue()
        ->and($mail->render())->toContain($invoice->number)
        ->and($pdf->as)->toBe("{$invoice->number}.pdf")
        ->and($pdf->attachWith(fn () => null, fn (Closure $data) => $data()))->toStartWith('%PDF');
});
