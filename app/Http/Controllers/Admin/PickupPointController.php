<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\PickupPoint;
use App\Support\Audit;
use App\Support\Geo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PickupPointController extends AdminController
{
    public function index(): Response
    {
        $points = PickupPoint::query()->selectRaw('pickup_points.*, ST_X(geom) AS lng, ST_Y(geom) AS lat')->orderBy('state')->orderBy('name')->get();

        return Inertia::render('admin/pickup-points/index', ['points' => $points]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'state' => ['required', 'in:ACT,NSW,NT,OT,QLD,SA,TAS,VIC,WA'],
            'lng' => ['required', 'numeric', 'between:112,160'],
            'lat' => ['required', 'numeric', 'between:-45,-9'],
            'opening_hours' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ], ['lng.required' => 'Pick an address from the G-NAF suggestions to set the location.']);
    }

    public function store(Request $request): RedirectResponse
    {
        $v = $this->validated($request);
        $p = new PickupPoint(collect($v)->except(['lng', 'lat'])->all());
        $p->setAttribute('geom', Geo::point((float) $v['lng'], (float) $v['lat']));
        $p->save();
        Audit::log('pickup_point.created', $p, collect($v)->except(['lng', 'lat'])->all());

        return back()->with('success', "Pickup point “{$p->name}” added.");
    }

    public function update(Request $request, PickupPoint $pickupPoint): RedirectResponse
    {
        $v = $this->validated($request);
        $pickupPoint->fill(collect($v)->except(['lng', 'lat'])->all());
        $pickupPoint->setAttribute('geom', Geo::point((float) $v['lng'], (float) $v['lat']));
        $pickupPoint->save();
        Audit::log('pickup_point.updated', $pickupPoint, collect($v)->except(['lng', 'lat'])->all());

        return back()->with('success', 'Pickup point updated.');
    }

    public function destroy(PickupPoint $pickupPoint): RedirectResponse
    {
        Audit::log('pickup_point.deleted', $pickupPoint, ['name' => $pickupPoint->name]);
        $pickupPoint->delete();

        return back()->with('success', 'Pickup point deleted.');
    }
}
