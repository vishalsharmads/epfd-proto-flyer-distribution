<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Gnaf\SuburbBuilder;
use App\Models\Suburb;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ServiceAreaController extends AdminController
{
    public function index(Request $request): Response
    {
        $q = trim($request->string('q')->toString());
        $state = strtoupper($request->string('state')->toString());
        $serviced = $request->string('serviced')->toString();
        $rows = Suburb::query()
            ->leftJoin('suburb_stats', 'suburb_stats.suburb_id', '=', 'suburbs.id')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('suburbs.name', 'ilike', "%{$q}%")->orWhere('suburbs.postcode', $q)))
            ->when($state !== '', fn ($w) => $w->where('suburbs.state', $state))
            ->when(in_array($serviced, ['yes', 'no'], true), fn ($w) => $w->where('suburbs.is_serviced', $serviced === 'yes'))
            ->orderBy('suburbs.state')->orderBy('suburbs.name')
            ->select(['suburbs.id', 'suburbs.name', 'suburbs.state', 'suburbs.postcode', 'suburbs.boundary_source', 'suburbs.is_serviced',
                'suburb_stats.houses', 'suburb_stats.units', 'suburb_stats.primary_with_units', 'suburb_stats.commercial', 'suburb_stats.letterboxes', 'suburb_stats.computed_at'])
            ->paginate(20)->withQueryString();

        return Inertia::render('admin/service-areas/index', [
            'suburbs' => $rows,
            'filters' => ['q' => $q ?: null, 'state' => $state ?: null, 'serviced' => $serviced ?: null],
            'states' => Suburb::distinct()->orderBy('state')->pluck('state'),
        ]);
    }

    public function show(Suburb $suburb): Response
    {
        $g = DB::selectOne('SELECT ST_AsGeoJSON(ST_SimplifyPreserveTopology(boundary, 0.00005), 6) AS g, ST_Area(epfd_to_mga(boundary)) / 1e6 AS km2 FROM suburbs WHERE id = ?', [$suburb->id]);
        $geom = $g?->g ? json_decode($g->g, true) : null;
        // AudienceBuilder renders Polygons: split a MultiPolygon into parts.
        $parts = [];
        if ($geom && $geom['type'] === 'MultiPolygon') {
            foreach ($geom['coordinates'] as $poly) {
                $parts[] = ['type' => 'Polygon', 'coordinates' => $poly];
            }
        } elseif ($geom) {
            $parts[] = $geom;
        }

        return Inertia::render('admin/service-areas/show', [
            'suburb' => $suburb->load('stats'),
            'parts' => $parts,
            'area_km2' => $g?->km2 ? round((float) $g->km2, 2) : null,
            'addresses' => DB::table('addresses')->where('state', $suburb->state)->whereRaw('upper(locality) = upper(?)', [$suburb->name])->count(),
        ]);
    }

    public function toggle(Request $request, Suburb $suburb): RedirectResponse
    {
        $data = $request->validate(['is_serviced' => ['required', 'boolean']]);
        $suburb->forceFill($data)->save();
        Audit::log('suburb.serviced_toggled', $suburb, $data);

        return back()->with('success', "{$suburb->name} ".($data['is_serviced'] ? 'is now serviced.' : 'is no longer serviced.'));
    }

    public function recompute(Suburb $suburb, SuburbBuilder $builder): RedirectResponse
    {
        $builder->refreshStats([$suburb->state]);
        Audit::log('suburb.stats_recomputed', $suburb, ['state' => $suburb->state]);

        return back()->with('success', "Stats recomputed for {$suburb->state}.");
    }
}
