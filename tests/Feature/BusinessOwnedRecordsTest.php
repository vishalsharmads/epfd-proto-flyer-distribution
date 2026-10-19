<?php

declare(strict_types=1);

use App\Models\SavedCard;
use App\Models\SupportThread;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;

function savedCardFor(int $businessId): SavedCard
{
    return SavedCard::create([
        'business_id' => $businessId, 'gateway' => 'mock', 'gateway_token' => 'tok_'.uniqid(), 'brand' => 'visa',
        'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2035, 'holder_name' => 'Tess', 'is_default' => true,
    ]);
}

function threadFor(int $businessId, int $userId): SupportThread
{
    return SupportThread::create(['business_id' => $businessId, 'user_id' => $userId, 'subject' => 'Help', 'status' => 'open']);
}

describe('records owned by another business', function () {
    it('cannot remove or default another business\'s saved card', function () {
        $card = savedCardFor(businessUser()->business_id);

        $this->actingAs(businessUser())->delete(route('app.account.cards.destroy', $card))->assertForbidden();
        $this->actingAs(businessUser())->post(route('app.account.cards.default', $card))->assertForbidden();
        expect($card->fresh())->not->toBeNull();
    });

    it('cannot delete another business\'s saved area', function () {
        $owner = businessUser();
        $areaId = DB::table('saved_areas')->insertGetId([
            'business_id' => $owner->business_id, 'name' => 'Area', 'geom' => Geo::fromGeoJson(rydalmerePolygon()),
            'houses' => 1, 'units' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(businessUser())->delete(route('app.account.areas.destroy', $areaId))->assertForbidden();
    });

    it('cannot read or reply to another business\'s support thread', function () {
        $owner = businessUser();
        $thread = threadFor($owner->business_id, $owner->id);
        $intruder = businessUser();

        $this->actingAs($intruder)->get(route('app.support.show', $thread))->assertForbidden();
        $this->actingAs($intruder)->post(route('app.support.reply', $thread), ['body' => 'Hello there'])->assertForbidden();
        $this->actingAs($owner)->get(route('app.support.show', $thread))->assertOk();
    });

    it('lets the owner remove their own card', function () {
        $owner = businessUser();
        $card = savedCardFor($owner->business_id);

        $this->actingAs($owner)->delete(route('app.account.cards.destroy', $card))->assertRedirect();
        expect($card->fresh())->toBeNull();
    });
});
