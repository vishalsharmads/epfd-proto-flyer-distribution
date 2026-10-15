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
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_name');
            $table->string('email');
            $table->string('mobile', 20);
            $table->string('abn', 11)->nullable();
            $table->string('industry')->nullable();
            $table->string('address');
            $table->string('address_gnaf_pid', 20)->nullable();
            $table->string('state', 3)->nullable();
            $table->geometry('geom', subtype: 'point', srid: 7844)->nullable();
            $table->string('status')->default('pending'); // pending|approved|suspended
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->text('status_note')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('business_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('mobile', 20)->nullable()->after('email');
            $table->string('otp_hash')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->unsignedSmallInteger('otp_attempts')->default(0);
            $table->boolean('is_distributor')->default(false);
            $table->json('preferences')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });

        Schema::create('rate_cards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(false);
            $table->json('data');
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('description')->nullable();
            $table->string('type'); // percent|fixed
            $table->unsignedInteger('value'); // percent (0-100) or cents
            $table->string('service')->default('any'); // any|distribution|print
            $table->unsignedInteger('min_subtotal_cents')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('print_products', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('category'); // flyer|brochure|magnet|business_card|poster|banner|catalogue
            $table->string('size_label');
            $table->unsignedInteger('width_mm');
            $table->unsignedInteger('height_mm');
            $table->unsignedInteger('bleed_mm')->default(3);
            $table->unsignedInteger('from_price_cents');
            $table->json('gsm_options');
            $table->json('finish_options');
            $table->json('quantity_options');
            $table->boolean('distributable')->default(false);
            $table->string('distribution_material')->nullable();
            $table->unsignedSmallInteger('turnaround_days')->default(3);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Print price matrix: per-piece price (ex GST, in 1/100 cents) per product/gsm/sides/qty.
        Schema::create('print_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_product_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('gsm');
            $table->unsignedTinyInteger('sides');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price_hc'); // hundredths of a cent, ex GST
            $table->unique(['print_product_id', 'gsm', 'sides', 'quantity']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 20)->unique();
            $table->string('name');
            $table->string('status')->default('draft');
            $table->unsignedTinyInteger('step')->default(1);
            $table->boolean('include_houses')->default(true);
            $table->boolean('include_units')->default(true);
            $table->string('coverage')->default('houses_units');
            $table->string('material')->default('standard_dl');
            $table->string('pickup_address')->nullable();
            $table->geometry('pickup_geom', subtype: 'point', srid: 7844)->nullable();
            $table->foreignId('pickup_point_id')->nullable()->constrained()->nullOnDelete();
            $table->date('available_from')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('houses')->default(0);
            $table->unsignedInteger('units')->default(0);
            $table->unsignedInteger('letterboxes')->default(0);
            $table->decimal('distance_km', 8, 2)->default(0);
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('gst_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->json('pricing')->nullable();
            $table->string('promo_code')->nullable();
            $table->foreignId('source_campaign_id')->nullable();
            $table->foreignId('print_order_id')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('rating_comment')->nullable();
            $table->string('proof_report_path')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['business_id', 'status']);
        });

        Schema::create('campaign_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('draw_mode')->default('pins'); // pins|trace|suburb
            $table->foreignId('suburb_id')->nullable();
            $table->geometry('geom', subtype: 'polygon', srid: 7844);
            $table->string('polygon_hash', 64);
            $table->unsignedInteger('houses')->default(0);
            $table->unsignedInteger('units')->default(0);
            $table->decimal('distance_km', 8, 2)->default(0);
            $table->unsignedInteger('fee_cents')->default(0);
            $table->timestamps();
        });
        DB::statement('CREATE INDEX campaign_areas_geom_gist ON campaign_areas USING GIST (geom)');

        Schema::create('saved_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->geometry('geom', subtype: 'polygon', srid: 7844);
            $table->unsignedInteger('houses')->default(0);
            $table->unsignedInteger('units')->default(0);
            $table->timestamps();
        });

        Schema::create('print_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 20)->unique();
            $table->foreignId('print_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('draft');
            $table->unsignedTinyInteger('step')->default(1);
            $table->unsignedSmallInteger('gsm')->nullable();
            $table->string('finish')->nullable();
            $table->unsignedTinyInteger('sides')->default(1);
            $table->string('orientation')->default('portrait');
            $table->unsignedInteger('quantity')->nullable();
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('gst_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->json('pricing')->nullable();
            $table->string('promo_code')->nullable();
            $table->string('delivery_name')->nullable();
            $table->string('delivery_address')->nullable();
            $table->string('delivery_phone')->nullable();
            $table->date('earliest_delivery')->nullable();
            $table->boolean('also_distribute')->default(false);
            $table->foreignId('campaign_id')->nullable();
            $table->foreignId('source_order_id')->nullable();
            $table->string('vendor')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('proof_path')->nullable();
            $table->boolean('right_to_print')->default(false);
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('rating_comment')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['business_id', 'status']);
        });

        Schema::create('artworks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_order_id')->constrained()->cascadeOnDelete();
            $table->string('side')->default('front'); // front|back
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('width_px')->nullable();
            $table->unsignedInteger('height_px')->nullable();
            $table->string('status')->default('pending'); // pending|approved|rejected
            $table->foreignId('reviewed_by')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 20)->unique();
            $table->string('name');
            $table->geometry('geom', subtype: 'polygon', srid: 7844);
            $table->unsignedInteger('flyers')->default(0);
            $table->unsignedInteger('houses')->default(0);
            $table->unsignedInteger('units')->default(0);
            $table->decimal('distance_km', 8, 2)->default(0);
            $table->unsignedInteger('pay_cents')->default(0);
            $table->date('deadline')->nullable();
            $table->foreignId('pickup_point_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('draft'); // draft|published|allocated|collected|in_delivery|completed|failed
            $table->foreignId('distributor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('coverage_pct', 5, 2)->nullable();
            $table->string('proof_report_path')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('allocated_at')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('run_gps_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->geometry('geom', subtype: 'point', srid: 7844);
            $table->decimal('accuracy_m', 6, 1)->nullable();
            $table->decimal('speed_mps', 6, 2)->nullable();
            $table->timestamp('recorded_at');
            $table->index(['run_id', 'recorded_at']);
        });

        Schema::create('saved_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('gateway');
            $table->string('gateway_token');
            $table->string('brand', 20);
            $table->string('last4', 4);
            $table->unsignedTinyInteger('exp_month');
            $table->unsignedSmallInteger('exp_year');
            $table->string('holder_name');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->morphs('payable');
            $table->string('gateway');
            $table->string('gateway_ref')->nullable()->index();
            $table->string('idempotency_key')->nullable()->unique();
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('credits_applied_cents')->default(0);
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->string('currency', 3)->default('AUD');
            $table->string('status'); // pending|succeeded|failed|refunded|partially_refunded
            $table->string('card_brand', 20)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('billing')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->morphs('invoiceable');
            $table->string('number')->unique();
            $table->json('lines');
            $table->json('billing')->nullable();
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('gst_cents');
            $table->unsignedInteger('total_cents');
            $table->unsignedInteger('credits_applied_cents')->default(0);
            $table->string('status')->default('paid'); // paid|refunded|void
            $table->timestamp('issued_at');
            $table->timestamps();
        });

        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->integer('amount_cents');
            $table->integer('balance_after_cents');
            $table->string('reason');
            $table->nullableMorphs('reference');
            $table->foreignId('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['credit_ledger', 'invoices', 'payments', 'saved_cards', 'run_gps_points', 'runs', 'artworks', 'print_orders', 'saved_areas', 'campaign_areas', 'campaigns', 'print_options', 'print_products', 'promo_codes', 'rate_cards'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_id');
            $table->dropColumn(['mobile', 'otp_hash', 'otp_expires_at', 'otp_attempts', 'is_distributor', 'preferences', 'last_login_at']);
        });
        Schema::dropIfExists('businesses');
    }
};
