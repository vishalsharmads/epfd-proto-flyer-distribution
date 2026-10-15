<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Business;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RolesSeeder::class, CatalogueSeeder::class, ContentSeeder::class]);

        // Staff: one user per admin role. Password for every seeded account: "password".
        foreach (['super_admin' => ['admin@epfd.test', 'Avery Admin'], 'operations' => ['ops@epfd.test', 'Olivia Operations'], 'finance' => ['finance@epfd.test', 'Felix Finance'], 'support' => ['support@epfd.test', 'Sam Support']] as $role => [$email, $name]) {
            User::updateOrCreate(['email' => $email], ['name' => $name, 'password' => 'password', 'email_verified_at' => now()])->syncRoles([$role]);
        }

        // Base demo business (approved, NSW). DemoDataSeeder adds the rest of the demo world.
        $biz = Business::updateOrCreate(['email' => 'owner@harbourcoffee.test'], [
            'name' => 'Harbour Coffee Co.', 'contact_name' => 'Hannah Lee', 'mobile' => '0412 345 678', 'abn' => '51824753556',
            'industry' => 'Hospitality', 'address' => '257 Victoria Road, Rydalmere NSW 2116', 'state' => 'NSW',
            'geom' => Geo::point(151.0365, -33.8126), 'status' => 'approved', 'approved_at' => now(),
        ]);
        User::updateOrCreate(['email' => 'demo@business.test'], ['name' => 'Hannah Lee', 'business_id' => $biz->id, 'mobile' => '0412 345 678', 'password' => 'password', 'email_verified_at' => now()]);

        if (class_exists(DemoDataSeeder::class)) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
