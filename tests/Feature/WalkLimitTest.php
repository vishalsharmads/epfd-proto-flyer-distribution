<?php

declare(strict_types=1);

use App\Domain\Campaigns\CampaignService;
use App\Domain\Runs\RunService;
use App\Exceptions\DomainProblem;
use App\Models\Campaign;
use App\Models\CampaignArea;

beforeEach(fn () => config(['epfd.areas.max_walk_km' => 30]));

function campaignWithAreaWalk(float $km, array $attrs = []): Campaign
{
    $user = businessUser();
    $campaign = Campaign::factory()->create(['business_id' => $user->business_id, 'user_id' => $user->id, 'step' => 2, ...$attrs]);
    CampaignArea::factory()->create(['campaign_id' => $campaign->id, 'name' => 'Big area', 'distance_km' => $km]);

    return $campaign->fresh();
}

it('sends the wizard back to Audience while an area is over the walk limit', function () {
    $campaign = campaignWithAreaWalk(45.2);

    $this->actingAs($campaign->user)
        ->get(route('app.campaigns.step', [$campaign, 'details']))
        ->assertRedirect(route('app.campaigns.step', [$campaign, 'audience']))
        ->assertSessionHas('error', 'Big area is a 45.2 km walk, over the 30 km limit for one distributor. Split it on the Audience step.');
});

it('lets the wizard continue when every area is within the limit', function () {
    $campaign = campaignWithAreaWalk(12.0);

    $this->actingAs($campaign->user)->get(route('app.campaigns.step', [$campaign, 'details']))->assertOk();
});

it('blocks payment while an area is over the limit', function () {
    $campaign = campaignWithAreaWalk(45.2);

    expect(fn () => app(CampaignService::class)->assertReadyForPayment($campaign))
        ->toThrow(fn (DomainProblem $e) => expect($e->extra['errors']['areas'][0])->toContain('over the 30 km limit'));
});

it('refuses to create an admin run over the limit', function () {
    config(['epfd.areas.max_walk_km' => 0.5]);
    $campaign = campaignWithAreaWalk(1.0, ['status' => 'submitted']);

    expect(fn () => app(RunService::class)->split($campaign, [['name' => 'Run 1', 'geometry' => rydalmerePolygon()]]))
        ->toThrow(DomainProblem::class, 'Run 1 is a');
    expect($campaign->runs()->count())->toBe(0);
});
