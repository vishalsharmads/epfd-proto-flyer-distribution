<?php

declare(strict_types=1);

use App\Domain\Settings\Settings;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => Cache::forget('settings:v1'));

it('shows every setting with its current value to super admins', function () {
    $this->actingAs(staffUser('super_admin'))
        ->get(route('admin.settings.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/index')
            ->where('settings', fn ($s) => collect($s)->firstWhere('key', 'areas.max_walk_km')['value'] == 30));
});

it('saves changed settings, applies them to config and shares the walking limit', function () {
    $admin = staffUser('super_admin');

    $this->actingAs($admin)
        ->put(route('admin.settings.update'), ['values' => ['areas' => ['max_walk_km' => 12.5], 'map' => ['stack' => 'classic']]])
        ->assertRedirect()
        ->assertSessionHas('success', 'Settings saved.');

    expect(Setting::find('areas.max_walk_km')->value)->toBe(12.5)
        ->and(Setting::find('map.stack')->value)->toBe('classic');

    Cache::forget('settings:v1');
    config(['epfd.areas.max_walk_km' => 30]);
    Settings::apply();
    expect(config('epfd.areas.max_walk_km'))->toBe(12.5);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('map.max_walk_km', 12.5)->where('map.stack', 'classic'));
});

it('rejects out-of-range values', function () {
    $this->actingAs(staffUser('super_admin'))
        ->put(route('admin.settings.update'), ['values' => ['areas' => ['max_walk_km' => 0], 'map' => ['stack' => 'leaflet']]])
        ->assertSessionHasErrors(['values.areas.max_walk_km', 'values.map.stack']);

    expect(Setting::count())->toBe(0);
});

it('is forbidden without settings.manage', function () {
    $this->actingAs(staffUser('support'))->get(route('admin.settings.show'))->assertForbidden();
});
