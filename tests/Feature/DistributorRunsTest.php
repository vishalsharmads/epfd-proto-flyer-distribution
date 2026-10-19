<?php

declare(strict_types=1);

use App\Models\Run;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function distributor(): User
{
    return User::factory()->create(['is_distributor' => true]);
}

function gpsPoints(int $count = 3): array
{
    return array_map(fn (int $i) => ['lng' => 151.0405 + $i * 0.0001, 'lat' => -33.8125, 'accuracy_m' => 5, 'recorded_at' => now()->addSeconds($i * 15)->toIso8601String()], range(0, $count - 1));
}

it('keeps the distributor endpoints to distributors', function () {
    Sanctum::actingAs(businessUser());

    $this->getJson('/api/v1/runs/available')->assertForbidden();
});

it('claims a published run once, then lists it as mine', function () {
    $run = Run::factory()->create(['status' => 'published', 'published_at' => now()]);
    $me = distributor();

    Sanctum::actingAs($me);
    $this->postJson("/api/v1/runs/{$run->id}/claim")->assertOk()->assertJsonPath('data.status', 'allocated');
    $this->getJson('/api/v1/runs/mine')->assertOk()->assertJsonPath('data.0.id', $run->id);

    Sanctum::actingAs(distributor());
    $this->postJson("/api/v1/runs/{$run->id}/claim")->assertStatus(409);
    $this->getJson('/api/v1/runs/mine')->assertOk()->assertJsonCount(0, 'data');
});

it('starts delivery on the first GPS batch and completes with coverage', function () {
    $me = distributor();
    $run = Run::factory()->create(['status' => 'allocated', 'distributor_id' => $me->id, 'allocated_at' => now()]);
    Sanctum::actingAs($me);

    $this->postJson("/api/v1/runs/{$run->id}/gps", ['points' => gpsPoints()])->assertStatus(202)->assertJsonPath('data.accepted', 3);
    expect($run->fresh()->status)->toBe('in_delivery');

    $this->postJson("/api/v1/runs/{$run->id}/submit")->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.coverage.gps_points', 3);
    $this->getJson('/api/v1/runs/mine?status=completed')->assertJsonPath('data.0.id', $run->id);
});

it('does not let a distributor touch someone else\'s run', function () {
    $run = Run::factory()->create(['status' => 'allocated', 'distributor_id' => distributor()->id]);
    Sanctum::actingAs(distributor());

    $this->postJson("/api/v1/runs/{$run->id}/gps", ['points' => gpsPoints(1)])->assertNotFound();
    $this->postJson("/api/v1/runs/{$run->id}/submit")->assertNotFound();
});

it('rejects GPS points outside Australia', function () {
    $me = distributor();
    $run = Run::factory()->create(['status' => 'allocated', 'distributor_id' => $me->id]);
    Sanctum::actingAs($me);

    $this->postJson("/api/v1/runs/{$run->id}/gps", ['points' => [['lng' => 0, 'lat' => 51.5, 'recorded_at' => now()->toIso8601String()]]])->assertStatus(422);
});
