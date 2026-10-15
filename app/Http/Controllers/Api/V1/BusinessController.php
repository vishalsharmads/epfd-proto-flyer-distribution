<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Areas\LetterboxEstimator;
use App\Http\Controllers\Api\V1\Concerns\ScopesToBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Http\Resources\CreditEntryResource;
use App\Http\Resources\SavedAreaResource;
use App\Http\Resources\SavedCardResource;
use App\Models\SavedArea;
use App\Rules\Abn;
use App\Support\Audit;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Business profile
 */
class BusinessController extends Controller
{
    use ScopesToBusiness;

    /** Your business profile. */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => BusinessResource::make($this->business($request))]);
    }

    /** Update your business profile (ABN optional, validated with the ATO checksum). */
    public function update(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'contact_name' => ['sometimes', 'string', 'max:120'],
            'mobile' => ['sometimes', 'string', 'regex:/^(\+?61|0)4\d{8}$/'],
            'abn' => ['sometimes', 'nullable', 'string', new Abn],
            'industry' => ['sometimes', 'nullable', 'string', 'max:80'],
            'address' => ['sometimes', 'string', 'max:255'],
            'lng' => ['sometimes', 'required_with:lat', 'numeric', 'between:112,155'],
            'lat' => ['sometimes', 'required_with:lng', 'numeric', 'between:-45,-9'],
        ]);
        if (array_key_exists('abn', $data)) {
            $data['abn'] = Abn::normalise($data['abn']);
        }
        if (isset($data['lng'], $data['lat'])) {
            $business->geom = Geo::point((float) $data['lng'], (float) $data['lat']);
        }
        unset($data['lng'], $data['lat']);
        $business->fill($data)->save();
        Audit::log('business.profile_updated', $business, array_keys($data));

        return response()->json(['data' => BusinessResource::make($business->fresh())]);
    }

    /** Credit ledger (newest first) with the current balance. */
    public function credits(Request $request): AnonymousResourceCollection
    {
        $business = $this->business($request);

        return CreditEntryResource::collection($business->creditEntries()->latest('id')->paginate($this->perPage($request)))
            ->additional(['balance_cents' => $business->creditBalanceCents()]);
    }

    /** Saved (tokenised) cards. Raw card numbers are never stored. */
    public function cards(Request $request): AnonymousResourceCollection
    {
        return SavedCardResource::collection($this->business($request)->savedCards()->orderByDesc('is_default')->get());
    }

    /** Delete a saved card. */
    public function destroyCard(Request $request, int $card): JsonResponse
    {
        $this->business($request)->savedCards()->findOrFail($card)->delete();

        return response()->json(null, 204);
    }

    /** Saved areas (reusable polygons). */
    public function areas(Request $request): AnonymousResourceCollection
    {
        return SavedAreaResource::collection(
            $this->business($request)->savedAreas()->withGeoJson()->latest('id')->paginate($this->perPage($request))
        );
    }

    /** Save an area. The polygon is validated and letterboxes are counted server-side. */
    public function storeArea(Request $request, LetterboxEstimator $estimator): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'geometry' => ['required', 'array'],
            'geometry.type' => ['required', 'in:Polygon'],
            'geometry.coordinates' => ['required', 'array'],
        ]);
        $r = $estimator->estimate($data['geometry'], true, true, null);
        $area = $business->savedAreas()->create([
            'name' => $data['name'],
            'geom' => Geo::fromGeoJson($r['polygon']),
            'houses' => $r['estimate']->houses,
            'units' => $r['estimate']->units,
        ]);

        return response()->json(['data' => SavedAreaResource::make($area->fresh())], 201);
    }

    /** Show a saved area. */
    public function showArea(Request $request, int $area): JsonResponse
    {
        return response()->json(['data' => SavedAreaResource::make($this->findArea($request, $area))]);
    }

    /** Rename / redraw a saved area. */
    public function updateArea(Request $request, int $area, LetterboxEstimator $estimator): JsonResponse
    {
        $model = $this->findArea($request, $area);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'geometry' => ['sometimes', 'array'],
            'geometry.type' => ['required_with:geometry', 'in:Polygon'],
            'geometry.coordinates' => ['required_with:geometry', 'array'],
        ]);
        if (isset($data['geometry'])) {
            $r = $estimator->estimate($data['geometry'], true, true, null);
            $model->geom = Geo::fromGeoJson($r['polygon']);
            $model->houses = $r['estimate']->houses;
            $model->units = $r['estimate']->units;
        }
        if (isset($data['name'])) {
            $model->name = $data['name'];
        }
        $model->save();

        return response()->json(['data' => SavedAreaResource::make($model->fresh())]);
    }

    /** Delete a saved area. */
    public function destroyArea(Request $request, int $area): JsonResponse
    {
        $this->findArea($request, $area)->delete();

        return response()->json(null, 204);
    }

    private function findArea(Request $request, int $id): SavedArea
    {
        return $this->business($request)->savedAreas()->withGeoJson()->findOrFail($id);
    }
}
