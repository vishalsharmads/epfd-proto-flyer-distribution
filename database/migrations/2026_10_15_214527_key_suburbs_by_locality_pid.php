<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Key suburbs by G-NAF LOCALITY_PID instead of (name, state): a state can hold several gazetted localities
 * with the same name (NSW has five "Mayfield"s), which name keys silently merged into one suburb.
 * Existing rows get their locality_pid from `php artisan suburbs:rekey` (or the next `gnaf:import`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('locality_pid', 20)->nullable()->after('locality');
            $table->index('locality_pid');
        });

        Schema::table('suburbs', function (Blueprint $table) {
            $table->dropUnique(['name', 'state']);
            $table->index(['name', 'state']);
            $table->unique('locality_pid');
            $table->string('boundary_matched_by', 12)->nullable()->after('boundary_source'); // locality_pid|name
        });

        Schema::table('suburb_import_runs', function (Blueprint $table) {
            $table->string('matched_by', 12)->default('name')->after('matched');
        });
    }

    public function down(): void
    {
        Schema::table('suburb_import_runs', function (Blueprint $table) {
            $table->dropColumn('matched_by');
        });

        Schema::table('suburbs', function (Blueprint $table) {
            $table->dropColumn('boundary_matched_by');
            $table->dropUnique(['locality_pid']);
            $table->dropIndex(['name', 'state']);
            $table->unique(['name', 'state']);
        });

        Schema::table('addresses', function (Blueprint $table) {
            $table->dropIndex(['locality_pid']);
            $table->dropColumn('locality_pid');
        });
    }
};
