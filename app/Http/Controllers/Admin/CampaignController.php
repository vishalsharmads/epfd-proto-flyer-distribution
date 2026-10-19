<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Runs\RunService;
use App\Http\Controllers\App\Support\Documents;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\CampaignArea;
use App\Models\PickupPoint;
use App\Models\Run;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignController extends AdminController
{
    public function __construct(private readonly RunService $runs) {}

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();
        $q = trim($request->string('q')->toString());
        $rows = Campaign::query()->with('business:id,name')->withCount('runs')
            ->where('status', '!=', 'draft')
            ->when(in_array($status, Campaign::STATUSES, true), fn ($w) => $w->where('status', $status))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('reference', 'ilike', "%{$q}%")->orWhere('name', 'ilike', "%{$q}%")->orWhereHas('business', fn ($b) => $b->where('name', 'ilike', "%{$q}%"))))
            ->latest('submitted_at')->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('admin/campaigns/index', [
            'campaigns' => $rows,
            'filters' => ['status' => $status ?: null, 'q' => $q ?: null],
            'counts' => Campaign::where('status', '!=', 'draft')->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    public function show(Campaign $campaign): Response
    {
        $campaign->load(['business:id,name,email', 'pickupPoint:id,name,address', 'printOrder:id,reference,status', 'invoice:id,invoiceable_type,invoiceable_id,number']);
        $areas = $campaign->areas()->withGeoJson()->get()->map(fn (CampaignArea $a) => [
            'id' => $a->id, 'name' => $a->name, 'draw_mode' => $a->draw_mode, 'houses' => $a->houses, 'units' => $a->units, 'geometry' => $a->geoJson(),
        ]);
        $runs = $campaign->runs()->withGeoJson()->with(['distributor:id,name', 'pickupPoint:id,name'])->withCount('gpsPoints')->orderBy('id')->get();
        $tracks = [];
        foreach ($runs->where('gps_points_count', '>', 0) as $run) {
            $pts = DB::select('SELECT ST_X(geom) AS lng, ST_Y(geom) AS lat FROM run_gps_points WHERE run_id = ? ORDER BY recorded_at, id', [$run->id]);
            $step = max(1, intdiv(count($pts), 1500));
            $tracks[] = ['run_id' => $run->id, 'points' => array_values(array_map(fn ($p) => [(float) $p->lng, (float) $p->lat], array_filter($pts, fn ($p, $i) => $i % $step === 0, ARRAY_FILTER_USE_BOTH)))];
        }
        $boundary = DB::selectOne('WITH u AS (SELECT ST_Union(geom) AS g FROM campaign_areas WHERE campaign_id = ?), parts AS (SELECT (ST_Dump(g)).geom AS p FROM u)
            SELECT ST_AsGeoJSON(p, 6) AS g FROM parts ORDER BY ST_Area(p) DESC LIMIT 1', [$campaign->id])?->g;

        return Inertia::render('admin/campaigns/show', [
            'campaign' => $campaign,
            'can_simulate_gps' => ! app()->isProduction(),
            'areas' => $areas,
            'boundary' => $boundary ? json_decode($boundary, true) : null,
            'runs' => $runs->map(fn (Run $r) => array_merge($r->toArray(), ['geometry' => $r->geoJson()])),
            'tracks' => $tracks,
            'pickup_points' => PickupPoint::where('is_active', true)->orderBy('name')->get(['id', 'name', 'state']),
            'distributors' => User::where('is_distributor', true)->orderBy('name')->get(['id', 'name', 'email']),
            'pay_per_item_cents' => $this->runs->payPerItemCents(),
            'transitions' => RunService::TRANSITIONS,
            'history' => AuditLog::where(fn ($w) => $w->where('auditable_type', $campaign->getMorphClass())->where('auditable_id', $campaign->id))
                ->orWhere(fn ($w) => $w->where('auditable_type', (new Run)->getMorphClass())->whereIn('auditable_id', $runs->pluck('id')))
                ->with('user:id,name')->latest('id')->limit(40)->get(),
        ]);
    }

    public function split(Request $request, Campaign $campaign): RedirectResponse
    {
        $data = $request->validate([
            'runs' => ['required', 'array', 'min:1', 'max:100'],
            'runs.*.name' => ['nullable', 'string', 'max:120'],
            'runs.*.geometry' => ['required', 'array'],
            'runs.*.geometry.type' => ['required', 'in:Polygon'],
            'runs.*.geometry.coordinates' => ['required', 'array'],
        ]);

        return $this->attempt(fn () => $this->runs->split($campaign, $data['runs']), count($data['runs']).' run(s) created.');
    }

    public function onePerArea(Campaign $campaign): RedirectResponse
    {
        return $this->attempt(fn () => $this->runs->onePerArea($campaign), 'One run created per area.');
    }

    public function publish(Campaign $campaign): RedirectResponse
    {
        return $this->attempt(fn () => $this->runs->publish($campaign), 'Runs published to the distributor job board.');
    }

    public function uploadProof(Request $request, Campaign $campaign): RedirectResponse
    {
        $request->validate(['report' => ['required', 'file', 'mimes:pdf', 'max:20480']]);
        $path = $request->file('report')->store("proofs/campaigns/{$campaign->id}", 'local');
        $campaign->forceFill(['proof_report_path' => $path])->save();
        Audit::log('campaign.proof_uploaded', $campaign, ['path' => $path]);

        return back()->with('success', 'Proof report uploaded — the business can now download it.');
    }

    /** Generate the proof-of-distribution PDF (business workstream's template) from runs + GPS and store it. */
    public function generateProof(Campaign $campaign): RedirectResponse
    {
        if (! class_exists(Documents::class)) {
            return back()->with('error', 'The proof report template is not available.');
        }
        $path = "proofs/campaigns/{$campaign->id}/{$campaign->reference}-proof-".now()->format('YmdHis').'.pdf';
        Storage::disk('local')->put($path, Documents::proofPdf($campaign)->output());
        $campaign->forceFill(['proof_report_path' => $path])->save();
        Audit::log('campaign.proof_generated', $campaign, ['path' => $path]);

        return back()->with('success', 'Proof report generated from runs and GPS tracks.');
    }

    public function proofFile(Campaign $campaign): StreamedResponse
    {
        abort_unless($campaign->proof_report_path && Storage::disk('local')->exists($campaign->proof_report_path), 404);

        return Storage::disk('local')->response($campaign->proof_report_path, "{$campaign->reference}-proof-report.pdf");
    }

    // ------------------------------------------------------------------ runs

    public function updateRun(Request $request, Run $run): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'flyers' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'pay' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'deadline' => ['sometimes', 'nullable', 'date'],
            'pickup_point_id' => ['sometimes', 'nullable', 'exists:pickup_points,id'],
        ]);
        if (array_key_exists('pay', $data)) {
            $data['pay_cents'] = (int) round($data['pay'] * 100);
            unset($data['pay']);
        }

        return $this->attempt(fn () => $this->runs->update($run, $data), "{$run->reference} updated.");
    }

    public function assignRun(Request $request, Run $run): RedirectResponse
    {
        $data = $request->validate(['distributor_id' => ['required', 'exists:users,id']]);

        return $this->attempt(fn () => $this->runs->assign($run, User::findOrFail($data['distributor_id'])), "{$run->reference} assigned.");
    }

    public function transitionRun(Request $request, Run $run): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:'.implode(',', Run::STATUSES)]]);

        return $this->attempt(fn () => $this->runs->transition($run, $data['status']), "{$run->reference} → ".str_replace('_', ' ', $data['status']).'.');
    }

    public function simulateRun(Run $run): RedirectResponse
    {
        abort_if(app()->isProduction(), 404);
        $result = null;
        $resp = $this->attempt(function () use ($run, &$result) {
            $result = $this->runs->simulateGps($run);
        }, 'GPS simulated.');
        if ($result) {
            return back()->with('success', "GPS simulated: {$result['points']} points, coverage ".($result['coverage_pct'] ?? 0).'%.');
        }

        return $resp;
    }

    public function destroyRun(Run $run): RedirectResponse
    {
        return $this->attempt(fn () => $this->runs->delete($run), 'Run deleted.');
    }
}
