<?php

declare(strict_types=1);

use App\Models\Business;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(DatabaseTransactions::class)->in('Feature', 'Unit');

/** Rydalmere NSW (sample data) — pickup point and a ~2 km² test polygon. */
const RYDALMERE_PICKUP = ['lng' => 151.0365, 'lat' => -33.8126];

function rydalmerePolygon(): array
{
    return Geo::polygon([[151.025, -33.815], [151.045, -33.815], [151.045, -33.800], [151.025, -33.800]]);
}

function businessUser(string $status = 'approved'): User
{
    $business = Business::create([
        'name' => 'Test Biz '.uniqid(), 'contact_name' => 'Tess Tester', 'email' => uniqid().'@biz.test', 'mobile' => '0400000000',
        'address' => '257 Victoria Road, Rydalmere NSW 2116', 'state' => 'NSW', 'status' => $status,
        'geom' => Geo::point(RYDALMERE_PICKUP['lng'], RYDALMERE_PICKUP['lat']),
    ]);

    return User::create([
        'name' => 'Tess Tester', 'email' => uniqid().'@user.test', 'password' => 'password',
        'business_id' => $business->id, 'email_verified_at' => now(),
    ])->fresh();
}

function staffUser(string $role): User
{
    $u = User::create(['name' => ucfirst($role), 'email' => uniqid().'@staff.test', 'password' => 'password', 'email_verified_at' => now()]);
    $u->assignRole($role);

    return $u->fresh();
}
