<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CRS helpers. Storage is GDA2020 geographic (EPSG:7844). Distances/areas are always computed after
 * projecting to the GDA2020 MGA zone (EPSG:7849–7856) that contains the geometry — never in degrees.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epfd_mga_srid(g geometry) RETURNS integer
            LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
                SELECT 7800 + least(56, greatest(49, floor((ST_X(ST_PointOnSurface(ST_Envelope(g))) + 180) / 6)::int + 1))
            $$;

            CREATE OR REPLACE FUNCTION epfd_to_mga(g geometry) RETURNS geometry
            LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
                SELECT ST_Transform(g, epfd_mga_srid(g))
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS epfd_to_mga(geometry); DROP FUNCTION IF EXISTS epfd_mga_srid(geometry);');
    }
};
