<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\App\Support\Presenter;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignArea;
use App\Models\PrintOrder;
use App\Models\Run;
use App\Models\SavedArea;
use App\Models\SupportThread;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** "My Jobs": unified list of campaigns + print orders, and the job detail pages. */
class JobController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $business = $request->user()->business;
        $campaigns = Campaign::where('business_id', $business->id)->latest('updated_at')->limit(200)
            ->get(['id', 'reference', 'name', 'status', 'step', 'total_cents', 'letterboxes', 'created_at', 'updated_at', 'submitted_at'])
            ->map(fn (Campaign $c) => [
                'key' => 'c'.$c->id, 'id' => $c->id, 'type' => 'campaign', 'reference' => $c->reference, 'name' => $c->name, 'status' => $c->status,
                'total_cents' => $c->total_cents, 'detail' => number_format($c->letterboxes).' letterboxes (est.)',
                'date' => ($c->submitted_at ?? $c->updated_at)?->toIso8601String(),
                'url' => $c->status === 'draft' ? route('app.campaigns.step', [$c, $c->step >= 3 ? ($c->step >= 4 ? 'confirm' : 'details') : 'audience']) : route('app.jobs.campaign', $c),
            ]);
        $orders = PrintOrder::where('business_id', $business->id)->with('product:id,name')->latest('updated_at')->limit(200)->get()
            ->map(fn (PrintOrder $o) => [
                'key' => 'p'.$o->id, 'id' => $o->id, 'type' => 'print', 'reference' => $o->reference,
                'name' => ($o->product?->name ?? 'Print order').($o->quantity ? ' × '.number_format($o->quantity) : ''),
                'status' => $o->status, 'total_cents' => $o->total_cents, 'detail' => $o->gsm ? "{$o->gsm}gsm · ".ucfirst((string) $o->finish) : 'Choose a product',
                'date' => ($o->submitted_at ?? $o->updated_at)?->toIso8601String(),
                'url' => $o->status === 'draft' ? route('app.print.step', [$o, $o->print_product_id ? ($o->step >= 4 ? 'confirm' : 'specs') : 'product']) : route('app.jobs.print', $o),
            ]);

        return Inertia::render('app/jobs/index', [
            'jobs' => $campaigns->concat($orders)->sortByDesc('date')->values(),
            'filter' => $request->query('filter', 'all'),
            'q' => (string) $request->query('q', ''),
        ]);
    }

    public function campaign(Campaign $campaign): Response|RedirectResponse
    {
        $this->authorize('view', $campaign);
        if ($campaign->isDraft()) {
            return redirect()->route('app.campaigns.step', [$campaign, 'audience']);
        }
        $runs = $campaign->runs()->withGeoJson()->withCount('gpsPoints')->orderBy('id')->get();

        return Inertia::render('app/jobs/campaign', [
            'campaign' => Presenter::campaign($campaign),
            'runs' => $runs->map(fn (Run $r) => Presenter::run($r, true)),
            'invoice' => $campaign->invoice ? Presenter::invoice($campaign->invoice) : null,
            'thread' => Presenter::thread($campaign->thread),
            'print_order' => $campaign->printOrder ? ['id' => $campaign->printOrder->id, 'reference' => $campaign->printOrder->reference, 'status' => $campaign->printOrder->status] : null,
            'timeline' => $this->campaignTimeline($campaign, $runs),
        ]);
    }

    public function print(PrintOrder $printOrder): Response|RedirectResponse
    {
        $this->authorize('view', $printOrder);
        if ($printOrder->status === 'draft') {
            return redirect()->route('app.print.step', [$printOrder, $printOrder->print_product_id ? 'specs' : 'product']);
        }
        $linked = $printOrder->campaign_id ? Campaign::find($printOrder->campaign_id) : Campaign::where('print_order_id', $printOrder->id)->latest('id')->first();

        return Inertia::render('app/jobs/print', [
            'order' => Presenter::printOrder($printOrder),
            'invoice' => $printOrder->invoice ? Presenter::invoice($printOrder->invoice) : null,
            'thread' => Presenter::thread($printOrder->thread),
            'linked_campaign' => $linked ? ['id' => $linked->id, 'name' => $linked->name, 'status' => $linked->status, 'url' => $linked->isDraft() ? route('app.campaigns.step', [$linked, 'audience']) : route('app.jobs.campaign', $linked)] : null,
            'timeline' => [
                'current' => $printOrder->status === 'cancelled' ? -1 : max(0, array_search($printOrder->status, PrintOrder::TIMELINE, true) ?: 0),
                'cancelled' => $printOrder->status === 'cancelled',
                'dates' => ['submitted' => $printOrder->submitted_at?->toIso8601String(), 'completed' => $printOrder->completed_at?->toIso8601String()],
            ],
        ]);
    }

    public function message(Request $request, string $type, int $id): RedirectResponse
    {
        $job = $this->job($type, $id);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:4000']], ['body.required' => 'Write a message first.']);
        DB::transaction(function () use ($job, $request, $data) {
            $thread = $job->thread ?? SupportThread::create([
                'business_id' => $job->business_id,
                'user_id' => $request->user()->id,
                'subject' => 'Job '.$job->reference,
                'status' => 'open',
                'channel' => 'job',
                'related_type' => $job->getMorphClass(),
                'related_id' => $job->id,
            ]);
            $thread->messages()->create(['user_id' => $request->user()->id, 'from_staff' => false, 'body' => $data['body']]);
            $thread->forceFill(['last_message_at' => now(), 'status' => 'open'])->save();
        });

        return back()->with('success', 'Message sent. The EPFD team usually replies within one business day.');
    }

    public function rate(Request $request, string $type, int $id): RedirectResponse
    {
        $job = $this->job($type, $id);
        abort_unless($job->status === 'completed', 422, 'You can rate a job once it is completed.');
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:1000']]);
        $job->forceFill(['rating' => $data['rating'], 'rating_comment' => $data['comment'] ?? null])->save();

        return back()->with('success', 'Thanks for your feedback!');
    }

    public function saveArea(Request $request, Campaign $campaign, CampaignArea $area): RedirectResponse
    {
        $this->authorize('view', $campaign);
        abort_unless($area->campaign_id === $campaign->id, 404);
        $data = $request->validate(['name' => ['nullable', 'string', 'max:80']]);
        $saved = SavedArea::create([
            'business_id' => $campaign->business_id,
            'name' => $data['name'] ?? $area->name,
            'geom' => DB::raw('(SELECT geom FROM campaign_areas WHERE id = '.(int) $area->id.')'),
            'houses' => $area->houses,
            'units' => $area->units,
        ]);

        return back()->with('success', "Saved “{$saved->name}” to your target areas.");
    }

    private function job(string $type, int $id): Campaign|PrintOrder
    {
        $job = $type === 'campaign' ? Campaign::findOrFail($id) : PrintOrder::findOrFail($id);
        $this->authorize('view', $job);

        return $job;
    }

    /** @param Collection<int, Run> $runs */
    private function campaignTimeline(Campaign $c, $runs): array
    {
        $current = $c->status === 'cancelled' ? -1 : max(0, array_search($c->status, Campaign::TIMELINE, true) ?: 0);

        return [
            'current' => $current,
            'cancelled' => $c->status === 'cancelled',
            'dates' => [
                'submitted' => $c->submitted_at?->toIso8601String(),
                'runs_published' => $runs->min('published_at')?->toIso8601String(),
                'allocated' => $runs->min('allocated_at')?->toIso8601String(),
                'collected' => $runs->min('collected_at')?->toIso8601String(),
                'in_delivery' => $runs->min('started_at')?->toIso8601String(),
                'completed' => $c->completed_at?->toIso8601String(),
            ],
        ];
    }
}
