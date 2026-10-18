<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\GnafRelease;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();
        $business = $user?->business;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified' => $user->email_verified_at !== null,
                    'roles' => $user->getRoleNames()->values(),
                    'permissions' => $user->isStaff() ? $user->getAllPermissions()->pluck('name')->values() : [],
                    'is_staff' => $user->isStaff(),
                ] : null,
                'business' => $business ? [
                    'id' => $business->id,
                    'name' => $business->name,
                    'status' => $business->status,
                    'can_pay' => $business->canPay(),
                    'state' => $business->state,
                ] : null,
            ],
            'brand' => config('epfd.brand'),
            'map' => [
                ...$this->mapStack(),
                'google_key' => config('epfd.map.google_key'),
                'base_tile_url' => config('epfd.map.base_tile_url'),
                'satellite_tile_url' => config('epfd.map.satellite_tile_url'),
                'attribution' => config('epfd.map.attribution'),
                'pickup_radius_km' => (int) config('epfd.areas.pickup_radius_km'),
                'max_walk_km' => (float) config('epfd.areas.max_walk_km'),
                'viewport_min_zoom' => (int) config('epfd.viewport.min_zoom'),
            ],
            'gnaf' => fn () => [
                'attribution' => config('epfd.licence.gnaf_attribution'),
                'licence_url' => config('epfd.licence.gnaf_licence_url'),
                'disclaimer' => config('epfd.licence.gnaf_disclaimer'),
                'release' => GnafRelease::current()?->label,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
        ];
    }

    /**
     * Resolve MAP_STACK into the map engine and drawing engine the front end uses.
     *
     * @return array{stack: string, provider: 'maplibre'|'google', draw_engine: 'overlay'|'terra'}
     */
    private function mapStack(): array
    {
        $stack = (string) config('epfd.map.stack', 'terra-google');
        if ($stack === 'terra-google' && ! config('epfd.map.google_key')) {
            $stack = 'terra-maplibre';
        }

        return match ($stack) {
            'classic' => ['stack' => 'classic', 'provider' => 'maplibre', 'draw_engine' => 'overlay'],
            'terra-maplibre' => ['stack' => 'terra-maplibre', 'provider' => 'maplibre', 'draw_engine' => 'terra'],
            default => ['stack' => 'terra-google', 'provider' => 'google', 'draw_engine' => 'terra'],
        };
    }
}
