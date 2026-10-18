<?php

declare(strict_types=1);

use App\Domain\Design\DesignService;
use App\Domain\Pricing\PricingFactory;
use App\Models\DesignOrder;
use Illuminate\Testing\TestResponse;

/** Publish the active rate card with $change applied to its design section, as finance. */
function publishDesign(callable $change): TestResponse
{
    $card = PricingFactory::rateCard();
    $card['design'] = $change($card['design']);
    $response = test()->actingAs(staffUser('finance'))->post('/admin/rate-card', ['name' => 'Design services '.uniqid(), 'data' => $card]);
    app()->forgetScopedInstances();

    return $response;
}

it('adds a design service and package that customers can order, with labels from the rate card', function () {
    publishDesign(function (array $design) {
        $design['packages']['express'] = ['label' => 'Express', 'revisions' => 2, 'turnaround_days' => 1, 'description' => 'Next-day first proof.'];
        foreach ($design['types'] as $k => $t) {
            $design['types'][$k]['prices']['express'] = 499;
        }
        $design['types']['logo'] = [
            'label' => 'Logo design', 'sides_option' => false, 'icon' => 'palette', 'description' => 'A logo you can use everywhere.',
            'print_categories' => [], 'default_product' => null,
            'prices' => ['basic' => 149, 'standard' => 249, 'premium' => 449, 'express' => 599],
        ];

        return $design;
    })->assertSessionHas('success');

    expect(DesignService::typeKeys())->toContain('logo')->and(DesignService::packageKeys())->toContain('express');

    $user = businessUser();
    $this->actingAs($user)->post('/app/design', ['design_type' => 'logo', 'package' => 'express'])->assertRedirect();
    $order = DesignOrder::where('business_id', $user->business_id)->latest('id')->firstOrFail();

    expect($order->design_type)->toBe('logo')
        ->and($order->subtotal_cents)->toBe(59900)
        ->and($order->displayName())->toBe('Logo design · Express');

    $this->get("/app/design/{$order->id}/service")->assertOk()
        ->assertInertia(fn ($page) => $page->where('catalogue.types', fn ($types) => collect($types)->contains(fn ($t) => $t['value'] === 'logo' && $t['description'] === 'A logo you can use everywhere.' && $t['icon'] === 'palette')));
});

it('stops new orders for a removed service while existing orders keep a readable name', function () {
    $user = businessUser();
    $this->actingAs($user)->post('/app/design', ['design_type' => 'stationery'])->assertRedirect();
    $existing = DesignOrder::where('business_id', $user->business_id)->latest('id')->firstOrFail();

    publishDesign(function (array $design) {
        unset($design['types']['stationery']);

        return $design;
    })->assertSessionHas('success');

    expect(DesignService::typeKeys())->not->toContain('stationery')
        ->and($existing->fresh()->typeLabel())->toBe('Stationery');

    $this->actingAs($user)->post('/app/design', ['design_type' => 'stationery'])->assertSessionHasErrors('design_type');
});

it('requires a price for every package and well-formed keys', function () {
    publishDesign(function (array $design) {
        $design['packages']['express'] = ['label' => 'Express', 'revisions' => 2, 'turnaround_days' => 1, 'description' => ''];

        return $design;
    })->assertSessionHasErrors('data.design');

    publishDesign(function (array $design) {
        $design['types']['Bad Key!'] = $design['types']['flyer'];

        return $design;
    })->assertSessionHasErrors('data.design');
});

it('opens the linked design service from a print category and prints with the default product', function () {
    publishDesign(function (array $design) {
        $design['types']['brochure']['print_categories'] = ['brochure', 'catalogue', 'magnet'];
        $design['types']['flyer']['print_categories'] = ['flyer'];
        $design['types']['flyer']['default_product'] = 'a6-flyers';

        return $design;
    })->assertSessionHas('success');

    expect(DesignService::typeForCategory('magnet'))->toBe('brochure')
        ->and(DesignService::typeForCategory('poster'))->toBe('flyer');

    $order = new DesignOrder(['design_type' => 'flyer']);
    expect(app(DesignService::class)->printProduct($order)?->slug)->toBe('a6-flyers');
});
