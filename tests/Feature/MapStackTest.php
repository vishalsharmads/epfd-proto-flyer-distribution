<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

dataset('stacks', [
    'terra-google with key' => ['terra-google', 'key-123', 'terra-google', 'google', 'terra'],
    'terra-google without key falls back' => ['terra-google', null, 'terra-maplibre', 'maplibre', 'terra'],
    'terra-maplibre' => ['terra-maplibre', 'key-123', 'terra-maplibre', 'maplibre', 'terra'],
    'classic' => ['classic', 'key-123', 'classic', 'maplibre', 'overlay'],
]);

it('shares the map stack resolved from MAP_STACK', function (string $stack, ?string $key, string $expectedStack, string $provider, string $drawEngine) {
    config(['epfd.map.stack' => $stack, 'epfd.map.google_key' => $key]);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->where('map.stack', $expectedStack)
        ->where('map.provider', $provider)
        ->where('map.draw_engine', $drawEngine));
})->with('stacks');
