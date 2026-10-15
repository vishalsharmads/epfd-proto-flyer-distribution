<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\PrintOrder;
use App\Models\Run;
use App\Models\SupportThread;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends AdminController
{
    public function __invoke(): Response
    {
        $tz = config('epfd.timezone');
        $monthStart = Carbon::now($tz)->startOfMonth()->utc();
        $yearStart = Carbon::now($tz)->startOfMonth()->subMonths(11);

        $revenue = DB::table('payments')->whereIn('status', ['succeeded', 'partially_refunded', 'refunded'])
            ->where('paid_at', '>=', $yearStart->copy()->utc())
            ->selectRaw("to_char(paid_at AT TIME ZONE 'UTC' AT TIME ZONE ?, 'YYYY-MM') AS m, sum(amount_cents + credits_applied_cents - refunded_cents) AS cents", [$tz])
            ->groupBy('m')->pluck('cents', 'm');
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $yearStart->copy()->addMonths($i);
            $months[] = ['month' => $m->format('M y'), 'cents' => (int) ($revenue[$m->format('Y-m')] ?? 0)];
        }

        $jobs = collect(Campaign::STATUSES)->reject(fn ($s) => $s === 'draft')->map(fn ($s) => [
            'status' => $s,
            'campaigns' => Campaign::where('status', $s)->count(),
            'print' => in_array($s, PrintOrder::STATUSES, true) ? PrintOrder::where('status', $s)->count() : 0,
        ])->values();
        foreach (['awaiting_artwork_approval', 'in_printing', 'shipped'] as $s) {
            $jobs->push(['status' => $s, 'campaigns' => 0, 'print' => PrintOrder::where('status', $s)->count()]);
        }

        return Inertia::render('admin/dashboard', [
            'kpis' => [
                'pending_approvals' => Business::where('status', 'pending')->count(),
                'runs_in_flight' => Run::whereIn('status', ['allocated', 'collected', 'in_delivery'])->count(),
                'revenue_month_cents' => (int) DB::table('payments')->whereIn('status', ['succeeded', 'partially_refunded', 'refunded'])->where('paid_at', '>=', $monthStart)
                    ->sum(DB::raw('amount_cents + credits_applied_cents - refunded_cents')),
                'open_support' => SupportThread::whereIn('status', ['open', 'pending'])->count(),
                'active_jobs' => Campaign::whereNotIn('status', ['draft', 'completed', 'cancelled'])->count() + PrintOrder::whereNotIn('status', ['draft', 'completed', 'cancelled'])->count(),
                'artwork_waiting' => PrintOrder::where('status', 'awaiting_artwork_approval')->count(),
            ],
            'jobs' => $jobs,
            'revenue' => $months,
            'activity' => AuditLog::with('user:id,name')->latest('id')->limit(12)->get()->map(fn (AuditLog $a) => [
                'id' => $a->id, 'action' => $a->action, 'user' => $a->user?->name, 'subject' => $a->auditable_type ? class_basename($a->auditable_type).' #'.$a->auditable_id : null,
                'created_at' => $a->created_at?->toIso8601String(),
            ]),
            'live' => Inertia::defer(fn () => $this->live()),
        ]);
    }

    /** Active runs with their polygons and latest GPS track (for the live map card). */
    private function live(): array
    {
        $runs = Run::withGeoJson()->with('campaign:id,reference,name')->whereIn('status', ['collected', 'in_delivery'])->latest('started_at')->limit(10)->get();
        $tracks = [];
        foreach ($runs as $run) {
            $pts = DB::select('SELECT ST_X(geom) AS lng, ST_Y(geom) AS lat FROM (SELECT geom, recorded_at FROM run_gps_points WHERE run_id = ? ORDER BY recorded_at DESC LIMIT 400) t ORDER BY recorded_at', [$run->id]);
            $tracks[] = ['run_id' => $run->id, 'points' => array_map(fn ($p) => [(float) $p->lng, (float) $p->lat], $pts)];
        }

        return [
            'runs' => $runs->map(fn (Run $r) => [
                'id' => $r->id, 'reference' => $r->reference, 'name' => $r->name, 'status' => $r->status, 'coverage_pct' => $r->coverage_pct,
                'campaign' => $r->campaign?->only(['id', 'reference', 'name']), 'geometry' => $r->geoJson(),
            ])->values(),
            'tracks' => $tracks,
        ];
    }
}
