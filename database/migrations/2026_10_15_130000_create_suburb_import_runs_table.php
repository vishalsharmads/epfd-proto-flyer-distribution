<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** History of `php artisan suburbs:import` runs (official ABS SAL / Geoscape boundaries). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suburb_import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 40);
            $table->string('path');
            $table->unsignedInteger('features')->default(0);
            $table->unsignedInteger('matched')->default(0);
            $table->json('unmatched')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suburb_import_runs');
    }
};
