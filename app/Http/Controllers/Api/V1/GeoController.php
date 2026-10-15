<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Areas\LetterboxEstimator;
use App\Domain\Gnaf\AddressClassifier;
use App\Domain\Pricing\PricingFactory;
use App\Domain\Pricing\PricingService;
use App\Http\Controllers\Controller;
use App\Models\PrintProduct;
use App\Models\Suburb;
use App\Support\BusinessDays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @tags Geo & estimates
 *
 * Coordinates are [longitude, latitude] in GDA2020 (EPSG:7844). WGS84 browser coordinates are accepted
 * as-is (≈1.8 m offset, negligible for letterbox counting).
 */
class GeoController extends Controller
{
    /**
     * Search suburbs (typeahead for the "Enter a suburb" draw mode).
     *
     * @queryParam q string required Partial suburb name or postcode. Example: rydal
     * @queryParam state string Optional state filter. Example: NSW
     */
    public function suburbs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:60'],
            'state' => ['nullable', 'string', 'size:3'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ]);
        $q = mb_strtolower(trim($data['q']));
        $rows = Suburb::query()
            ->leftJoin('suburb_stats', 'suburb_stats.suburb_id', '=', 'suburbs.id')
            ->where('suburbs.is_serviced', true)
            ->when($data['state'] ?? null, fn ($w, $s) => $w->where('suburbs.state', strtoupper($s)))
            ->where(fn ($w) => $w->whereRaw('lower(suburbs.name) LIKE ?', [$q.'%'])
                ->orWhereRaw('lower(suburbs.name) % ?', [$q])
                ->orWhere('suburbs.postcode', $q))
            ->orderByRaw('lower(suburbs.name) LIKE ? DESC, similarity(lower(suburbs.name), ?) DESC', [$q.'%', $q])
            ->limit($data['limit'] ?? 8)
            ->get(['suburbs.id', 'suburbs.name', 'suburbs.state', 'suburbs.postcode', 'suburbs.boundary_source',
                'suburb_stats.houses', 'suburb_stats.units', 'suburb_stats.letterboxes',
                DB::raw('ST_X(suburbs.centroid) AS lng'), DB::raw('ST_Y(suburbs.centroid) AS lat')]);

        return response()->json(['data' => $rows->map(fn ($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'state' => $s->state,
            'postcode' => $s->postcode,
            'label' => "{$s->name} {$s->state} {$s->postcode}",
            'boundary_source' => $s->boundary_source,
            'approximate' => $s->boundary_source === 'derived',
            'centroid' => $s->lng !== null ? [(float) $s->lng, (float) $s->lat] : null,
            'stats' => ['houses' => (int) $s->houses, 'units' => (int) $s->units, 'letterboxes' => (int) $s->letterboxes, 'estimated' => true],
        ])]);
    }

    /**
     * Suburb boundary polygon (GeoJSON, simplified, largest part) ready to drop in as an editable area.
     */
    public function suburbBoundary(Suburb $suburb): JsonResponse
    {
        $row = DB::selectOne('
            WITH b AS (SELECT boundary AS g FROM suburbs WHERE id = ?),
                 parts AS (SELECT (ST_Dump(g)).geom AS p FROM b),
                 biggest AS (SELECT p FROM parts ORDER BY ST_Area(p) DESC LIMIT 1),
                 simple AS (SELECT ST_Transform(ST_SimplifyPreserveTopology(epfd_to_mga(p), 15), 7844) AS s FROM biggest)
            SELECT ST_AsGeoJSON(ST_ExteriorRing(s), 6) AS ring, ST_NPoints(s) AS n FROM simple', [$suburb->id]);
        abort_if(! $row || ! $row->ring, 404, 'This suburb has no boundary.');
        $ring = json_decode($row->ring, true)['coordinates'];
        // Keep the editable pin count reasonable (max 120 vertices).
        if (count($ring) > 120) {
            $step = (int) ceil(count($ring) / 120);
            $ring = array_values(array_filter($ring, fn ($_, $i) => $i % $step === 0, ARRAY_FILTER_USE_BOTH));
            $ring[] = $ring[0];
        }

        return response()->json([
            'id' => $suburb->id,
            'name' => $suburb->name,
            'state' => $suburb->state,
            'boundary_source' => $suburb->boundary_source,
            'approximate' => $suburb->boundary_source === 'derived',
            'geometry' => ['type' => 'Polygon', 'coordinates' => [$ring]],
        ]);
    }

    /**
     * Address typeahead over G-NAF (trigram index).
     *
     * @queryParam q string required Example: 257 victoria rd rydalmere
     */
    public function addressSearch(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:3', 'max:120'], 'limit' => ['nullable', 'integer', 'min:1', 'max:20']]);
        $q = mb_strtolower(preg_replace('/\s+/', ' ', trim($data['q'])) ?? '');
        // G-NAF stores full street types ("ROAD"); expand common abbreviations typed by users.
        $abbr = ['rd' => 'road', 'st' => 'street', 'ave' => 'avenue', 'av' => 'avenue', 'pl' => 'place', 'cres' => 'crescent', 'cr' => 'crescent',
            'dr' => 'drive', 'ct' => 'court', 'pde' => 'parade', 'hwy' => 'highway', 'ln' => 'lane', 'tce' => 'terrace', 'cl' => 'close', 'cct' => 'circuit'];
        $q = (string) preg_replace_callback('/\b('.implode('|', array_keys($abbr)).')\b/', fn ($m) => $abbr[$m[1]], $q);
        $q = str_replace(',', '', $q);

        $rows = DB::select('
            SELECT gnaf_pid, full_address, address_kind, state, locality, postcode, ST_X(geom) AS lng, ST_Y(geom) AS lat
            FROM addresses
            WHERE search_text % ? OR search_text LIKE ?
            ORDER BY search_text LIKE ? DESC, similarity(search_text, ?) DESC
            LIMIT ?', [$q, $q.'%', $q.'%', $q, $data['limit'] ?? 8]);

        return response()->json(['data' => array_map(fn ($r) => [
            'gnaf_pid' => $r->gnaf_pid,
            'label' => $r->full_address,
            'kind' => $r->address_kind,
            'state' => $r->state,
            'locality' => $r->locality,
            'postcode' => $r->postcode,
            'location' => [(float) $r->lng, (float) $r->lat],
        ], $rows)]);
    }

    /**
     * G-NAF address points inside a bounding box (map "Show addresses" layer). Capped.
     *
     * @queryParam bbox string required minLng,minLat,maxLng,maxLat. Example: 151.03,-33.815,151.04,-33.808
     * @queryParam kind string house|unit|all. Example: all
     */
    public function viewport(Request $request, AddressClassifier $classifier): JsonResponse
    {
        $data = $request->validate([
            'bbox' => ['required', 'string', 'regex:/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?){3}$/'],
            'kind' => ['nullable', 'in:house,unit,all'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('epfd.viewport.max_points')],
        ]);
        [$minX, $minY, $maxX, $maxY] = array_map('floatval', explode(',', $data['bbox']));
        abort_if($maxX - $minX > 0.05 || $maxY - $minY > 0.05, 422, 'Zoom in to see addresses (bbox too large).');
        $limit = (int) ($data['limit'] ?? config('epfd.viewport.max_points'));
        $kind = $data['kind'] ?? 'all';
        $countable = $classifier->countableSql('a');

        $rows = DB::select("
            SELECT gnaf_pid, address_kind, full_address, ST_X(geom) AS lng, ST_Y(geom) AS lat
            FROM addresses a
            WHERE geom && ST_MakeEnvelope(?, ?, ?, ?, 7844) AND {$countable}
              ".($kind !== 'all' ? 'AND address_kind = ?' : '').'
            LIMIT ?', array_values(array_filter([$minX, $minY, $maxX, $maxY, $kind !== 'all' ? $kind : null, $limit + 1], fn ($v) => $v !== null)));

        $capped = count($rows) > $limit;

        return response()->json([
            'type' => 'FeatureCollection',
            'capped' => $capped,
            'features' => array_map(fn ($r) => [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [(float) $r->lng, (float) $r->lat]],
                'properties' => ['id' => $r->gnaf_pid, 'kind' => $r->address_kind, 'label' => $r->full_address],
            ], array_slice($rows, 0, $limit)),
        ]);
    }

    /**
     * Estimate an area: polygon in → houses, units, distance and fee out (all estimated).
     *
     * Validates the polygon server-side and enforces the 20 km pickup rule when `pickup` is given.
     */
    public function estimate(Request $request, LetterboxEstimator $estimator, PricingService $pricing): JsonResponse
    {
        $data = $request->validate([
            'geometry' => ['required', 'array'],
            'geometry.type' => ['required', 'in:Polygon'],
            'geometry.coordinates' => ['required', 'array'],
            'include_houses' => ['boolean'],
            'include_units' => ['boolean'],
            'coverage' => ['nullable', 'in:houses_units,houses_only,units_only'],
            'material' => ['nullable', 'string', 'max:40'],
            'pickup' => ['nullable', 'array'],
            'pickup.lng' => ['required_with:pickup', 'numeric', 'between:-180,180'],
            'pickup.lat' => ['required_with:pickup', 'numeric', 'between:-90,90'],
        ]);

        $r = $estimator->estimate($data['geometry'], (bool) ($data['include_houses'] ?? true), (bool) ($data['include_units'] ?? true), $data['pickup'] ?? null);
        $est = $r['estimate'];
        $quote = $est->isEmpty() ? null : $pricing->distribution([
            'houses' => $est->houses,
            'units' => $est->units,
            'coverage' => $data['coverage'] ?? 'houses_units',
            'material' => $data['material'] ?? 'standard_dl',
        ]);

        return response()->json([
            'estimate' => $est->toArray(),
            'quote' => $quote?->toArray(),
            'fee_available' => $quote !== null,
            'message' => $est->isEmpty() ? 'No letterboxes detected. Fees unavailable, redraw.' : null,
            'polygon_hash' => $r['hash'],
            'cached' => $r['cached'],
            'crs' => 'EPSG:7844',
        ]);
    }

    /** Distribution price quote (no polygon) — the same PricingService as the UI and admin preview. */
    public function distributionQuote(Request $request, PricingService $pricing): JsonResponse
    {
        $card = $pricing->rateCard()['distribution'];
        $data = $request->validate([
            'houses' => ['required', 'integer', 'min:0', 'max:1000000'],
            'units' => ['required', 'integer', 'min:0', 'max:1000000'],
            'coverage' => ['nullable', 'in:'.implode(',', array_keys($card['coverage']))],
            'material' => ['nullable', 'in:'.implode(',', array_keys($card['materials']))],
            'promo_code' => ['nullable', 'string', 'max:40'],
        ]);
        $quote = $pricing->distribution([
            ...$data,
            'promo' => PricingFactory::promo($data['promo_code'] ?? null, 'distribution'),
        ]);

        return response()->json(['data' => $quote->toArray()]);
    }

    /** Print price quote. */
    public function printQuote(Request $request, PricingService $pricing): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:print_products,id'],
            'gsm' => ['required', 'integer'],
            'sides' => ['required', 'integer', 'in:1,2'],
            'quantity' => ['required', 'integer', 'min:1'],
            'finish' => ['nullable', 'in:matt,gloss'],
            'addons' => ['nullable', 'array'],
            'addons.*' => ['string'],
            'promo_code' => ['nullable', 'string', 'max:40'],
        ]);
        $product = PrintProduct::findOrFail($data['product_id']);
        $quote = $pricing->print([
            ...$data,
            'product_name' => $product->name,
            'promo' => PricingFactory::promo($data['promo_code'] ?? null, 'print'),
        ]);
        $quote = $quote->toArray();
        $quote['meta']['earliest_delivery'] = BusinessDays::add((int) $quote['meta']['turnaround_days'] + (in_array('express', $data['addons'] ?? [], true) ? -2 : 0) + 2)->toDateString();

        return response()->json(['data' => $quote]);
    }
}
