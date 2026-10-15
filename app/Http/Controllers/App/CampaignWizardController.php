<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Campaigns\CampaignService;
use App\Domain\Payments\CheckoutService;
use App\Exceptions\DomainProblem;
use App\Http\Controllers\App\Support\HandlesProblems;
use App\Http\Controllers\App\Support\Presenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\PayRequest;
use App\Models\Campaign;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Distribution wizard: Campaign → Audience → Details → Confirm. Drafts autosave through JSON endpoints. */
class CampaignWizardController extends Controller
{
    use AuthorizesRequests;
    use HandlesProblems;

    private const STEPS = ['audience' => 2, 'details' => 3, 'confirm' => 4];

    public function __construct(private readonly CampaignService $campaigns) {}

    public function start(Request $request): Response
    {
        $business = $request->user()->business;

        return Inertia::render('app/campaigns/start', [
            'drafts' => Campaign::where('business_id', $business->id)->where('status', 'draft')
                ->with(['areas' => fn ($q) => $q->withGeoJson()])->latest('updated_at')->limit(12)->get()->map(fn ($c) => Presenter::campaignCard($c)),
            'past' => Campaign::where('business_id', $business->id)->whereIn('status', ['completed', 'in_delivery', 'collected', 'allocated', 'runs_published', 'submitted'])
                ->with(['areas' => fn ($q) => $q->withGeoJson()])->latest('submitted_at')->limit(12)->get()->map(fn ($c) => Presenter::campaignCard($c)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $campaign = $this->campaigns->createDraft($request->user()->business, $request->user());

        return redirect()->route('app.campaigns.step', [$campaign, 'audience']);
    }

    public function rerun(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorize('view', $campaign);
        try {
            $copy = $this->campaigns->rerun($campaign, $request->user());
        } catch (DomainProblem $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('app.campaigns.step', [$copy, 'audience'])->with('success', "Copied “{$campaign->name}” into a new draft.");
    }

    public function destroy(Campaign $campaign): RedirectResponse
    {
        $this->authorize('delete', $campaign);
        $campaign->delete();

        return back()->with('success', 'Draft deleted.');
    }

    public function step(Request $request, Campaign $campaign, string $step): Response|RedirectResponse
    {
        $this->authorize('view', $campaign);
        if (! $campaign->isDraft()) {
            return redirect()->route('app.jobs.campaign', $campaign);
        }
        $n = self::STEPS[$step];
        if ($campaign->step < $n) {
            $campaign->forceFill(['step' => $n])->saveQuietly();
        }
        $business = $request->user()->business;

        return Inertia::render('app/campaigns/'.$step, [
            'campaign' => Presenter::campaign($campaign),
            'options' => Presenter::distributionOptions($campaign),
            'payment' => $step === 'confirm' ? Presenter::paymentContext($business) : null,
        ]);
    }

    /** PUT areas: every polygon is re-validated and re-estimated on the server. */
    public function syncAreas(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('update', $campaign);
        $data = $request->validate([
            'areas' => ['present', 'array', 'max:50'],
            'areas.*.name' => ['nullable', 'string', 'max:80'],
            'areas.*.draw_mode' => ['nullable', 'in:pins,trace,suburb'],
            'areas.*.suburb_id' => ['nullable', 'integer'],
            'areas.*.geometry' => ['required', 'array'],
            'areas.*.geometry.type' => ['required', 'in:Polygon'],
            'areas.*.geometry.coordinates' => ['required', 'array'],
        ]);
        try {
            // Normalise optional keys (the domain service indexes them directly).
            $areas = array_map(fn (array $a) => [
                'name' => $a['name'] ?? null,
                'draw_mode' => $a['draw_mode'] ?? 'pins',
                'suburb_id' => $a['suburb_id'] ?? null,
                'geometry' => $a['geometry'],
            ], $data['areas']);
            $campaign = $this->campaigns->syncAreas($campaign, $areas);
        } catch (DomainProblem $e) {
            return $this->problemJson($e);
        }

        return response()->json(['data' => Presenter::campaign($campaign), 'options' => Presenter::distributionOptions($campaign)]);
    }

    /** PATCH autosave of wizard fields. Pickup changes re-validate the 20 km rule (rolled back on failure). */
    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('update', $campaign);
        $card = Presenter::distributionOptions($campaign);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'include_houses' => ['sometimes', 'boolean'],
            'include_units' => ['sometimes', 'boolean'],
            'coverage' => ['sometimes', 'in:'.implode(',', array_column($card['coverages'], 'value'))],
            'material' => ['sometimes', 'in:'.implode(',', array_column($card['materials'], 'value'))],
            'pickup_address' => ['sometimes', 'required', 'string', 'max:255'],
            'pickup_lng' => ['required_with:pickup_address', 'numeric', 'between:112,155'],
            'pickup_lat' => ['required_with:pickup_address', 'numeric', 'between:-45,-9'],
            'available_from' => ['sometimes', 'required', 'date', 'after_or_equal:'.now(config('epfd.timezone'))->toDateString()],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'promo_code' => ['sometimes', 'nullable', 'string', 'max:40'],
        ], [
            'available_from.after_or_equal' => 'Choose today or a later date.',
            'pickup_lng.required_with' => 'Choose the pickup address from the list.',
        ]);
        if (isset($data['include_houses'], $data['include_units']) && ! $data['include_houses'] && ! $data['include_units']) {
            return response()->json(['detail' => 'Include houses, units or both.', 'errors' => ['include_houses' => ['Include houses, units or both.']]], 422);
        }
        try {
            $campaign = DB::transaction(fn () => $this->campaigns->update($campaign, $data));
        } catch (DomainProblem $e) {
            if ($e->problemCode === 'promo_invalid') {
                return response()->json(['detail' => $e->getMessage(), 'errors' => ['promo_code' => [$e->getMessage()]]], 422);
            }
            if ($e->problemCode === 'area_outside_pickup_radius') {
                return response()->json(['detail' => $e->getMessage(), 'errors' => ['pickup_address' => [$e->getMessage().' Choose a closer pickup or redraw your areas.']]], 422);
            }

            return $this->problemJson($e);
        }

        return response()->json(['data' => Presenter::campaign($campaign), 'options' => Presenter::distributionOptions($campaign)]);
    }

    public function nameCheck(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'campaign_id' => ['nullable', 'integer']]);
        $available = $this->campaigns->nameAvailable($request->user()->business, $data['name'], isset($data['campaign_id']) ? (int) $data['campaign_id'] : null);

        return response()->json(['available' => $available, 'message' => $available ? 'This name is available' : 'You already have a campaign with this name.']);
    }

    public function pay(PayRequest $request, Campaign $campaign, CheckoutService $checkout): RedirectResponse
    {
        $this->authorize('pay', $campaign);
        try {
            $this->campaigns->assertReadyForPayment($campaign);
            $checkout->pay($campaign, $request->user(), $request->checkoutInput());
        } catch (DomainProblem $e) {
            return back()->withErrors($this->problemErrors($e));
        }

        return redirect()->route('app.jobs.campaign', $campaign)->with('success', 'Payment received — your campaign has been submitted.');
    }
}
