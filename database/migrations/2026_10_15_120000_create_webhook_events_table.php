<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Processed payment-gateway webhook events (idempotency for POST /api/v1/webhooks/{gateway}). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 20);
            $table->string('event_id', 191);
            $table->string('type', 80);
            $table->string('gateway_ref')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('result', 40)->default('processed');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
