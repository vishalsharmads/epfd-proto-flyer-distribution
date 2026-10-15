<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design Studio: graphic design orders (flyer, brochure, business card, presentation folder, stationery)
 * and their files (brief uploads, versioned proofs, final files). Messages reuse support_threads
 * (channel 'job', related morph 'design_order').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 20)->unique();
            $table->string('design_type')->default('flyer'); // flyer|brochure|business_card|presentation_folder|stationery
            $table->string('package')->default('standard'); // basic|standard|premium
            $table->string('size')->nullable();
            $table->foreignId('print_product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('sides')->default(1);
            $table->string('status')->default('draft'); // draft|submitted|in_design|proof_ready|changes_requested|approved|completed|cancelled
            $table->unsignedTinyInteger('step')->default(2);
            // Brief
            $table->string('headline')->nullable();
            $table->text('key_message')->nullable();
            $table->string('call_to_action')->nullable();
            $table->text('contact_details')->nullable();
            $table->string('brand_colours')->nullable();
            $table->text('style_notes')->nullable();
            $table->string('target_audience')->nullable();
            $table->date('due_date')->nullable();
            // Revisions
            $table->unsignedSmallInteger('revisions_included')->default(1);
            $table->unsignedSmallInteger('revisions_used')->default(0);
            // Money
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('gst_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->json('pricing')->nullable();
            $table->string('promo_code')->nullable();
            // Fulfilment
            $table->foreignId('designer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('print_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['business_id', 'status']);
            $table->index('status');
        });

        Schema::create('design_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_order_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // brief_upload|proof|final
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['design_order_id', 'kind', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_assets');
        Schema::dropIfExists('design_orders');
    }
};
