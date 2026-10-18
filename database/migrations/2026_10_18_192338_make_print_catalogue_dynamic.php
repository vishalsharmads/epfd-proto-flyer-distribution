<?php

declare(strict_types=1);

use Database\Seeders\RolesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed print catalogue: categories become rows, products get a description, image and the sides they
 * can be printed on (was hard-coded: banners and magnets single-sided), and price-matrix cells may be empty
 * until an admin prices them (a product with empty cells cannot be activated).
 */
return new class extends Migration
{
    /** @var list<array{0:string,1:string,2:string}> slug, name, icon */
    private const CATEGORIES = [
        ['flyer', 'Flyers', 'file-text'],
        ['brochure', 'Brochures', 'book-open'],
        ['magnet', 'Magnets', 'magnet'],
        ['business_card', 'Business cards', 'credit-card'],
        ['poster', 'Posters', 'image'],
        ['banner', 'Banners', 'flag'],
        ['catalogue', 'Catalogues', 'newspaper'],
    ];

    public function up(): void
    {
        Schema::create('print_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 60);
            $table->string('icon', 40)->default('printer');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $existing = DB::table('print_products')->distinct()->pluck('category')->all();
        $rows = [];
        foreach (self::CATEGORIES as $i => [$slug, $name, $icon]) {
            $rows[] = ['slug' => $slug, 'name' => $name, 'icon' => $icon, 'sort' => $i, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_diff($existing, array_column(self::CATEGORIES, 0)) as $i => $slug) {
            $rows[] = ['slug' => $slug, 'name' => ucwords(str_replace('_', ' ', $slug)).'s', 'icon' => 'printer', 'sort' => 100 + $i, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('print_categories')->insert($rows);

        Schema::table('print_products', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->string('image_path')->nullable()->after('description');
            $table->json('sides_options')->nullable()->after('gsm_options');
            $table->foreign('category')->references('slug')->on('print_categories')->cascadeOnUpdate()->restrictOnDelete();
        });
        DB::table('print_products')->whereIn('category', ['banner', 'magnet'])->update(['sides_options' => json_encode([1])]);
        DB::table('print_products')->whereNull('sides_options')->update(['sides_options' => json_encode([1, 2])]);

        Schema::table('print_options', function (Blueprint $table) {
            $table->unsignedInteger('unit_price_hc')->nullable()->change();
        });

        // New `catalogue.manage` permission (operations + finance; super_admin gets everything).
        if (Schema::hasTable('roles') && DB::table('roles')->exists()) {
            (new RolesSeeder)->run();
        }
    }

    public function down(): void
    {
        DB::table('print_options')->whereNull('unit_price_hc')->delete();
        Schema::table('print_options', function (Blueprint $table) {
            $table->unsignedInteger('unit_price_hc')->nullable(false)->change();
        });
        Schema::table('print_products', function (Blueprint $table) {
            $table->dropForeign(['category']);
            $table->dropColumn(['description', 'image_path', 'sides_options']);
        });
        Schema::dropIfExists('print_categories');
    }
};
