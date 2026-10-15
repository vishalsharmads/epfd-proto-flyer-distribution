<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Pricing\PricingFactory;
use App\Http\Controllers\Api\V1\Concerns\ScopesToBusiness;
use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use App\Models\RateCard;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * @tags Admin · Pricing
 */
class PricingController extends Controller
{
    use ScopesToBusiness;

    /** The active rate card plus version history (latest 20). */
    public function rateCard(): JsonResponse
    {
        $active = RateCard::where('is_active', true)->latest('id')->first();

        return response()->json(['data' => [
            'id' => $active?->id,
            'name' => $active?->name ?? 'Defaults',
            'data' => PricingFactory::rateCard(),
            'versions' => RateCard::latest('id')->limit(20)->get(['id', 'name', 'is_active', 'created_by', 'created_at']),
        ]]);
    }

    /** Publish a new rate-card version (previous versions are kept, deactivated). Requires `rate_card.manage`. */
    public function updateRateCard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'data' => ['required', 'array'],
            'data.distribution' => ['required', 'array'],
            'data.print' => ['required', 'array'],
        ]);
        $card = DB::transaction(function () use ($data, $request) {
            RateCard::where('is_active', true)->update(['is_active' => false]);

            return RateCard::create(['name' => $data['name'], 'data' => $data['data'], 'is_active' => true, 'created_by' => $request->user()->id]);
        });
        Audit::log('rate_card.published', $card);

        return response()->json(['data' => ['id' => $card->id, 'name' => $card->name, 'is_active' => true, 'data' => $card->data]], 201);
    }

    /** List promo codes. */
    public function promoIndex(Request $request): JsonResponse
    {
        return response()->json(PromoCode::latest('id')->paginate($this->perPage($request)));
    }

    /** Create a promo code. */
    public function promoStore(Request $request): JsonResponse
    {
        $promo = PromoCode::create($this->promoRules($request, null));
        Audit::log('promo.created', $promo);

        return response()->json(['data' => $promo], 201);
    }

    /** Show a promo code. */
    public function promoShow(int $promo): JsonResponse
    {
        return response()->json(['data' => PromoCode::findOrFail($promo)]);
    }

    /** Update a promo code. */
    public function promoUpdate(Request $request, int $promo): JsonResponse
    {
        $p = PromoCode::findOrFail($promo);
        $p->update($this->promoRules($request, $p));
        Audit::log('promo.updated', $p);

        return response()->json(['data' => $p]);
    }

    /** Delete a promo code. */
    public function promoDestroy(int $promo): JsonResponse
    {
        $p = PromoCode::findOrFail($promo);
        $p->delete();
        Audit::log('promo.deleted', $p);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function promoRules(Request $request, ?PromoCode $p): array
    {
        $s = $p ? ['sometimes'] : [];
        $data = $request->validate([
            'code' => [...$s, 'required', 'string', 'max:40', 'alpha_dash', Rule::unique('promo_codes', 'code')->ignore($p?->id)],
            'description' => [...$s, 'nullable', 'string', 'max:255'],
            'type' => [...$s, 'required', 'in:percent,fixed'],
            'value' => [...$s, 'required', 'integer', 'min:1', 'max:10000000'],
            'service' => [...$s, 'in:any,distribution,print'],
            'min_subtotal_cents' => [...$s, 'integer', 'min:0'],
            'starts_at' => [...$s, 'nullable', 'date'],
            'ends_at' => [...$s, 'nullable', 'date', 'after:starts_at'],
            'max_uses' => [...$s, 'nullable', 'integer', 'min:1'],
            'is_active' => [...$s, 'boolean'],
        ]);
        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        return $data;
    }
}
