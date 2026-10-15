<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\App\Support\Presenter;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Run;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Letterbox Distribution hub: current runs board + my campaigns. */
class CampaignController extends Controller
{
    public function hub(Request $request): Response
    {
        $business = $request->user()->business;
        $runs = Run::whereIn('campaign_id', Campaign::where('business_id', $business->id)->select('id'))
            ->whereIn('status', ['published', 'allocated', 'collected', 'in_delivery'])
            ->with('campaign:id,name,reference')
            ->withGeoJson()->latest('updated_at')->limit(60)->get();

        $columns = ['to_allocate' => [], 'to_collect' => [], 'out_for_delivery' => []];
        foreach ($runs as $r) {
            $key = match ($r->status) {
                'published' => 'to_allocate',
                'allocated' => 'to_collect',
                default => 'out_for_delivery',
            };
            $columns[$key][] = Presenter::run($r, $key === 'out_for_delivery');
        }

        return Inertia::render('app/distribution/index', [
            'board' => $columns,
            'campaigns' => Campaign::where('business_id', $business->id)->with(['areas' => fn ($q) => $q->withGeoJson()])
                ->latest('updated_at')->limit(50)->get()->map(fn ($c) => Presenter::campaignCard($c)),
        ]);
    }
}
