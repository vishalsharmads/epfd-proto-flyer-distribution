<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-managed payment gateway credentials (encrypted), per-gateway customer ids on businesses,
     * and payment meta for intents waiting on customer authentication (3-D Secure).
     */
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->string('driver')->primary();
            $table->string('mode')->default('test'); // test|live
            $table->text('credentials')->nullable(); // encrypted JSON
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->json('gateway_customers')->nullable(); // {"stripe": "cus_…"}
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->json('meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('meta'));
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn('gateway_customers'));
        Schema::dropIfExists('payment_gateways');
    }
};
