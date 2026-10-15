<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Gnaf\OpenDataCatalogue;
use App\Models\GnafRelease;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → G-NAF data status → Import guide: what to import, when, from where, and how. */
class GnafGuideController extends AdminController
{
    public function __invoke(OpenDataCatalogue $catalogue): Response
    {
        $current = GnafRelease::current();
        $states = $current && ! $current->is_sample ? $current->states : (array) config('epfd.gnaf.default_states');

        return Inertia::render('admin/gnaf/guide', [
            'current' => $current?->only(['label', 'states', 'is_sample', 'imported_at', 'crs']),
            'states' => array_values($states),
            'latest' => Inertia::defer(fn () => $catalogue->latest()),
            'coverage' => DB::selectOne("
                SELECT count(*) AS total,
                       count(*) FILTER (WHERE boundary_source = 'official') AS official,
                       count(*) FILTER (WHERE boundary_source = 'derived' AND locality_pid IS NOT NULL) AS derived,
                       count(*) FILTER (WHERE locality_pid IS NULL) AS unkeyed
                FROM suburbs"),
            'last_boundary_import' => DB::table('suburb_import_runs')->latest('id')->first(['source', 'matched_by', 'created_at']),
        ]);
    }
}
