<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\GnafRelease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class GnafController extends AdminController
{
    public function __invoke(): Response
    {
        $byState = DB::table('addresses')->selectRaw("state, count(*) AS total, count(*) FILTER (WHERE address_kind = 'house') AS houses, count(*) FILTER (WHERE address_kind = 'unit') AS units, count(*) FILTER (WHERE is_primary_with_units) AS primaries, count(*) FILTER (WHERE is_commercial) AS commercial")
            ->groupBy('state')->orderBy('state')->get();
        $suburbs = DB::table('suburbs')->selectRaw("state, count(*) AS total, count(*) FILTER (WHERE boundary_source = 'official') AS official")->groupBy('state')->orderBy('state')->get();

        return Inertia::render('admin/gnaf/index', [
            'current' => GnafRelease::current(),
            'releases' => GnafRelease::latest('id')->limit(20)->get(),
            'by_state' => $byState,
            'suburbs' => $suburbs,
            'imports' => Schema::hasTable('suburb_import_runs') ? DB::table('suburb_import_runs')->latest('id')->limit(10)->get()->map(function ($r) {
                $r->unmatched = json_decode((string) $r->unmatched, true) ?? [];

                return $r;
            }) : [],
            'licence' => [
                'attribution' => config('epfd.licence.gnaf_attribution'),
                'url' => config('epfd.licence.gnaf_licence_url'),
                'disclaimer' => config('epfd.licence.gnaf_disclaimer'),
            ],
        ]);
    }
}
