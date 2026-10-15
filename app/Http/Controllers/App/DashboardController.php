<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Run;
use App\Models\SupportThread;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $business = $request->user()->business;
        $tz = config('epfd.timezone');
        $runs = Run::whereIn('campaign_id', Campaign::where('business_id', $business->id)->select('id'));

        $completed = (clone $runs)->where('status', 'completed')->count();
        $failed = (clone $runs)->where('status', 'failed')->count();
        $inFlight = (clone $runs)->whereIn('status', ['published', 'allocated', 'collected', 'in_delivery'])->count();

        // 12-month "flyers distributed" series from completed runs (Australia/Sydney months).
        $start = now($tz)->startOfMonth()->subMonths(11);
        $byMonth = (clone $runs)->where('status', 'completed')->where('completed_at', '>=', $start->copy()->utc())
            ->get(['flyers', 'completed_at'])
            ->groupBy(fn (Run $r) => Carbon::parse($r->completed_at)->setTimezone($tz)->format('Y-m'))
            ->map(fn ($g) => (int) $g->sum('flyers'));
        $series = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $start->copy()->addMonths($i);
            $series[] = ['month' => $m->format('M'), 'label' => $m->format('F Y'), 'flyers' => $byMonth[$m->format('Y-m')] ?? 0];
        }

        $openThreads = SupportThread::where('business_id', $business->id)->whereIn('status', ['open', 'pending'])->latest('last_message_at')->limit(5)
            ->get(['id', 'subject', 'status', 'channel', 'last_message_at', 'created_at']);

        return Inertia::render('app/dashboard', [
            'stats' => [
                'total_flyers' => (int) Campaign::where('business_id', $business->id)->whereNotNull('paid_at')->sum('letterboxes'),
                'active_runs' => $inFlight,
                'completed_runs' => $completed,
                'failed_runs' => $failed,
                'open_threads' => SupportThread::where('business_id', $business->id)->whereIn('status', ['open', 'pending'])->count(),
                'credits_cents' => $business->creditBalanceCents(),
                'drafts' => Campaign::where('business_id', $business->id)->where('status', 'draft')->count(),
            ],
            'outcomes' => ['successful' => $completed, 'failed' => $failed, 'in_flight' => $inFlight],
            'monthly' => $series,
            'threads' => $openThreads->map(fn ($t) => [
                'id' => $t->id, 'subject' => $t->subject, 'status' => $t->status, 'channel' => $t->channel,
                'last_message_at' => ($t->last_message_at ?? $t->created_at)?->toIso8601String(),
            ]),
        ]);
    }
}
