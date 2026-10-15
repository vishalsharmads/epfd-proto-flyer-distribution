<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('gnaf_releases', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('crs')->default('GDA2020');
            $table->unsignedInteger('srid')->default(7844);
            $table->json('states');
            $table->boolean('is_sample')->default(false);
            $table->string('status')->default('running'); // running|completed|failed
            $table->json('row_counts')->nullable();
            $table->json('skipped_counts')->nullable();
            $table->json('kind_counts')->nullable();
            $table->json('discovery')->nullable();
            $table->string('source_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->string('gnaf_pid', 20)->unique();
            $table->string('state', 3);
            $table->string('locality', 100);
            $table->string('postcode', 4)->nullable();
            $table->string('street_name', 100)->nullable();
            $table->string('street_type', 20)->nullable();
            $table->string('street_suffix', 10)->nullable();
            $table->string('street_locality_pid', 20)->nullable();
            $table->integer('number_first')->nullable();
            $table->integer('number_last')->nullable();
            $table->string('flat_type', 10)->nullable();
            $table->string('flat_number', 20)->nullable();
            $table->string('level_number', 20)->nullable();
            $table->string('building_name', 200)->nullable();
            $table->string('lot_number', 20)->nullable();
            $table->string('address_kind', 5); // house|unit
            $table->boolean('is_primary_with_units')->default(false);
            $table->boolean('is_commercial')->default(false);
            $table->string('primary_pid', 20)->nullable();
            $table->string('address_site_pid', 20)->nullable();
            $table->string('geocode_type', 4)->nullable();
            $table->smallInteger('confidence')->nullable();
            $table->string('full_address', 300);
            $table->string('search_text', 300);
            $table->geometry('geom', subtype: 'point', srid: 7844);
            $table->index(['state', 'locality', 'postcode']);
        });
        DB::statement('CREATE INDEX addresses_geom_gist ON addresses USING GIST (geom)');
        DB::statement('CREATE INDEX addresses_search_trgm ON addresses USING GIN (search_text gin_trgm_ops)');

        Schema::create('suburbs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('state', 3);
            $table->string('postcode', 4)->nullable();
            $table->string('locality_pid', 20)->nullable();
            $table->string('boundary_source', 10)->default('derived'); // derived|official
            $table->geometry('boundary', subtype: 'multipolygon', srid: 7844)->nullable();
            $table->geometry('centroid', subtype: 'point', srid: 7844)->nullable();
            $table->boolean('is_serviced')->default(true);
            $table->timestamps();
            $table->unique(['name', 'state']);
        });
        DB::statement('CREATE INDEX suburbs_boundary_gist ON suburbs USING GIST (boundary)');
        DB::statement('CREATE INDEX suburbs_name_trgm ON suburbs USING GIN (lower(name) gin_trgm_ops)');

        Schema::create('suburb_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suburb_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('houses')->default(0);
            $table->unsignedInteger('units')->default(0);
            $table->unsignedInteger('primary_with_units')->default(0);
            $table->unsignedInteger('commercial')->default(0);
            $table->unsignedInteger('letterboxes')->default(0);
            $table->timestamp('computed_at')->nullable();
        });

        Schema::create('pickup_points', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address');
            $table->string('state', 3);
            $table->geometry('geom', subtype: 'point', srid: 7844);
            $table->string('opening_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_points');
        Schema::dropIfExists('suburb_stats');
        Schema::dropIfExists('suburbs');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('gnaf_releases');
    }
};
