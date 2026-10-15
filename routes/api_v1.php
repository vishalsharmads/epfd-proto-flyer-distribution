<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\DesignController;
use App\Http\Controllers\Api\V1\Distributor\RunController as DistributorRunController;
use App\Http\Controllers\Api\V1\PrintController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

/*
| Owned by the API workstream: auth, profile, campaigns, print, design, payments, support, admin, distributor, webhooks.
| Included from routes/api.php inside the `throttle:api` group (prefix /api/v1).
| Docs: /docs (Swagger UI, offline) and /docs/openapi.json.
*/

// --- Auth ------------------------------------------------------------------------------------
Route::prefix('auth')->name('api.auth.')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('password/forgot', [AuthController::class, 'forgotPassword'])->name('password.forgot');
    });
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:auth')->name('otp.verify');
        Route::post('otp/resend', [AuthController::class, 'resendOtp'])->middleware('throttle:auth')->name('otp.resend');
    });
});

// --- Public catalogue -------------------------------------------------------------------------
Route::get('print/products', [PrintController::class, 'products'])->name('api.print.products');
Route::get('print/products/{product}/options', [PrintController::class, 'options'])->whereNumber('product')->name('api.print.options');
Route::get('design/services', [DesignController::class, 'services'])->name('api.design.services');
Route::post('pricing/design/quote', [DesignController::class, 'quote'])->name('api.pricing.design');

// --- Payment webhooks (no auth; signature verified) -------------------------------------------
Route::post('webhooks/{gateway}', [WebhookController::class, 'handle'])->whereIn('gateway', ['mock', 'stripe'])
    ->withoutMiddleware('throttle:api')->name('api.webhooks');

// --- Business (authenticated) -----------------------------------------------------------------
Route::middleware('auth:sanctum')->name('api.')->group(function () {
    Route::get('business', [BusinessController::class, 'show'])->name('business.show');
    Route::patch('business', [BusinessController::class, 'update'])->name('business.update');
    Route::get('business/credits', [BusinessController::class, 'credits'])->name('business.credits');
    Route::get('business/cards', [BusinessController::class, 'cards'])->name('business.cards');
    Route::delete('business/cards/{card}', [BusinessController::class, 'destroyCard'])->whereNumber('card')->name('business.cards.destroy');
    Route::get('business/areas', [BusinessController::class, 'areas'])->name('business.areas.index');
    Route::post('business/areas', [BusinessController::class, 'storeArea'])->middleware('throttle:estimate')->name('business.areas.store');
    Route::get('business/areas/{area}', [BusinessController::class, 'showArea'])->whereNumber('area')->name('business.areas.show');
    Route::patch('business/areas/{area}', [BusinessController::class, 'updateArea'])->whereNumber('area')->name('business.areas.update');
    Route::delete('business/areas/{area}', [BusinessController::class, 'destroyArea'])->whereNumber('area')->name('business.areas.destroy');

    Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::post('campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->whereNumber('campaign')->name('campaigns.show');
    Route::patch('campaigns/{campaign}', [CampaignController::class, 'update'])->whereNumber('campaign')->name('campaigns.update');
    Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])->whereNumber('campaign')->name('campaigns.destroy');
    Route::put('campaigns/{campaign}/areas', [CampaignController::class, 'syncAreas'])->whereNumber('campaign')->middleware('throttle:estimate')->name('campaigns.areas');
    Route::post('campaigns/{campaign}/submit', [CampaignController::class, 'submit'])->whereNumber('campaign')->name('campaigns.submit');
    Route::post('campaigns/{campaign}/pay', [CampaignController::class, 'pay'])->whereNumber('campaign')->middleware('throttle:payments')->name('campaigns.pay');
    Route::get('campaigns/{campaign}/tracking', [CampaignController::class, 'tracking'])->whereNumber('campaign')->name('campaigns.tracking');

    Route::get('print/orders', [PrintController::class, 'index'])->name('print.orders.index');
    Route::post('print/orders', [PrintController::class, 'store'])->name('print.orders.store');
    Route::get('print/orders/{order}', [PrintController::class, 'show'])->whereNumber('order')->name('print.orders.show');
    Route::patch('print/orders/{order}', [PrintController::class, 'update'])->whereNumber('order')->name('print.orders.update');
    Route::delete('print/orders/{order}', [PrintController::class, 'destroy'])->whereNumber('order')->name('print.orders.destroy');
    Route::post('print/orders/{order}/artwork', [PrintController::class, 'uploadArtwork'])->whereNumber('order')->name('print.orders.artwork.store');
    Route::delete('print/orders/{order}/artwork/{artwork}', [PrintController::class, 'destroyArtwork'])->whereNumber(['order', 'artwork'])->name('print.orders.artwork.destroy');
    Route::post('print/orders/{order}/pay', [PrintController::class, 'pay'])->whereNumber('order')->middleware('throttle:payments')->name('print.orders.pay');

    Route::get('design/orders', [DesignController::class, 'index'])->name('design.orders.index');
    Route::post('design/orders', [DesignController::class, 'store'])->name('design.orders.store');
    Route::get('design/orders/{order}', [DesignController::class, 'show'])->whereNumber('order')->name('design.orders.show');
    Route::patch('design/orders/{order}', [DesignController::class, 'update'])->whereNumber('order')->name('design.orders.update');
    Route::delete('design/orders/{order}', [DesignController::class, 'destroy'])->whereNumber('order')->name('design.orders.destroy');
    Route::post('design/orders/{order}/assets', [DesignController::class, 'uploadAssets'])->whereNumber('order')->name('design.orders.assets.store');
    Route::post('design/orders/{order}/pay', [DesignController::class, 'pay'])->whereNumber('order')->middleware('throttle:payments')->name('design.orders.pay');
    Route::post('design/orders/{order}/approve', [DesignController::class, 'approve'])->whereNumber('order')->name('design.orders.approve');
    Route::post('design/orders/{order}/request-changes', [DesignController::class, 'requestChanges'])->whereNumber('order')->name('design.orders.request-changes');

    Route::get('payments', [BillingController::class, 'payments'])->name('payments.index');
    Route::get('invoices', [BillingController::class, 'invoices'])->name('invoices.index');
    Route::get('invoices/{invoice}', [BillingController::class, 'invoice'])->whereNumber('invoice')->name('invoices.show');

    Route::get('support/threads', [SupportController::class, 'index'])->name('support.threads.index');
    Route::post('support/threads', [SupportController::class, 'store'])->name('support.threads.store');
    Route::get('support/threads/{thread}', [SupportController::class, 'show'])->whereNumber('thread')->name('support.threads.show');
    Route::post('support/threads/{thread}/messages', [SupportController::class, 'reply'])->whereNumber('thread')->name('support.threads.reply');

    // --- Distributor stubs (users with is_distributor = true) ---------------------------------
    Route::get('runs/available', [DistributorRunController::class, 'available'])->name('runs.available');
    Route::post('runs/{run}/claim', [DistributorRunController::class, 'claim'])->whereNumber('run')->name('runs.claim');
    Route::post('runs/{run}/gps', [DistributorRunController::class, 'gps'])->whereNumber('run')->name('runs.gps');
    Route::post('runs/{run}/submit', [DistributorRunController::class, 'submit'])->whereNumber('run')->name('runs.submit');
});

// --- Admin (staff + permission) ----------------------------------------------------------------
Route::prefix('admin')->middleware(['auth:sanctum', 'staff'])->name('api.admin.')->group(function () {
    Route::middleware('permission:businesses.view')->group(function () {
        Route::get('businesses', [Admin\BusinessController::class, 'index'])->name('businesses.index');
        Route::get('businesses/{business}', [Admin\BusinessController::class, 'show'])->whereNumber('business')->name('businesses.show');
    });
    Route::middleware('permission:businesses.manage')->group(function () {
        Route::post('businesses/{business}/approve', [Admin\BusinessController::class, 'approve'])->whereNumber('business')->name('businesses.approve');
        Route::post('businesses/{business}/suspend', [Admin\BusinessController::class, 'suspend'])->whereNumber('business')->name('businesses.suspend');
    });
    Route::post('businesses/{business}/credits', [Admin\BusinessController::class, 'credits'])->whereNumber('business')->middleware('permission:credits.manage')->name('businesses.credits');

    Route::middleware('permission:campaigns.view')->group(function () {
        Route::get('campaigns', [Admin\CampaignController::class, 'index'])->name('campaigns.index');
        Route::get('campaigns/{campaign}', [Admin\CampaignController::class, 'show'])->whereNumber('campaign')->name('campaigns.show');
    });
    Route::middleware('permission:campaigns.manage')->group(function () {
        Route::post('campaigns/{campaign}/runs', [Admin\CampaignController::class, 'createRuns'])->whereNumber('campaign')->name('campaigns.runs.store');
        Route::post('runs/{run}/publish', [Admin\CampaignController::class, 'publishRun'])->whereNumber('run')->name('runs.publish');
        Route::post('runs/{run}/assign', [Admin\CampaignController::class, 'assignRun'])->whereNumber('run')->name('runs.assign');
        Route::patch('runs/{run}/status', [Admin\CampaignController::class, 'runStatus'])->whereNumber('run')->name('runs.status');
    });

    Route::middleware('permission:print_orders.view')->group(function () {
        Route::get('print-orders', [Admin\PrintOrderController::class, 'index'])->name('print-orders.index');
        Route::get('print-orders/{order}', [Admin\PrintOrderController::class, 'show'])->whereNumber('order')->name('print-orders.show');
    });
    Route::middleware('permission:print_orders.manage')->group(function () {
        Route::patch('print-orders/{order}/status', [Admin\PrintOrderController::class, 'updateStatus'])->whereNumber('order')->name('print-orders.status');
        Route::post('artworks/{artwork}/approve', [Admin\PrintOrderController::class, 'approveArtwork'])->whereNumber('artwork')->name('artworks.approve');
        Route::post('artworks/{artwork}/reject', [Admin\PrintOrderController::class, 'rejectArtwork'])->whereNumber('artwork')->name('artworks.reject');
    });
    Route::post('print-orders/{order}/refund', [Admin\PrintOrderController::class, 'refund'])->whereNumber('order')->middleware('permission:payments.refund')->name('print-orders.refund');

    Route::middleware('permission:design_orders.view')->group(function () {
        Route::get('design-orders', [Admin\DesignOrderController::class, 'index'])->name('design-orders.index');
        Route::get('design-orders/{order}', [Admin\DesignOrderController::class, 'show'])->whereNumber('order')->name('design-orders.show');
    });
    Route::middleware('permission:design_orders.manage')->group(function () {
        Route::patch('design-orders/{order}/status', [Admin\DesignOrderController::class, 'updateStatus'])->whereNumber('order')->name('design-orders.status');
        Route::post('design-orders/{order}/proofs', [Admin\DesignOrderController::class, 'uploadProof'])->whereNumber('order')->name('design-orders.proofs');
    });

    Route::get('payments', [Admin\PaymentController::class, 'index'])->middleware('permission:payments.view')->name('payments.index');
    Route::post('payments/{payment}/refund', [Admin\PaymentController::class, 'refund'])->whereNumber('payment')->middleware('permission:payments.refund')->name('payments.refund');

    Route::middleware('permission:rate_card.manage')->group(function () {
        Route::get('rate-card', [Admin\PricingController::class, 'rateCard'])->name('rate-card.show');
        Route::put('rate-card', [Admin\PricingController::class, 'updateRateCard'])->name('rate-card.update');
        Route::get('promo-codes', [Admin\PricingController::class, 'promoIndex'])->name('promo-codes.index');
        Route::post('promo-codes', [Admin\PricingController::class, 'promoStore'])->name('promo-codes.store');
        Route::get('promo-codes/{promo}', [Admin\PricingController::class, 'promoShow'])->whereNumber('promo')->name('promo-codes.show');
        Route::patch('promo-codes/{promo}', [Admin\PricingController::class, 'promoUpdate'])->whereNumber('promo')->name('promo-codes.update');
        Route::delete('promo-codes/{promo}', [Admin\PricingController::class, 'promoDestroy'])->whereNumber('promo')->name('promo-codes.destroy');
    });

    Route::get('gnaf/releases', [Admin\OpsController::class, 'gnafReleases'])->middleware('permission:gnaf.view')->name('gnaf.releases');
    Route::get('suburbs', [Admin\OpsController::class, 'suburbs'])->middleware('permission:service_areas.manage')->name('suburbs.index');
    Route::patch('suburbs/{suburb}', [Admin\OpsController::class, 'updateSuburb'])->whereNumber('suburb')->middleware('permission:service_areas.manage')->name('suburbs.update');
    Route::get('support/threads', [Admin\OpsController::class, 'threads'])->middleware('permission:support.view')->name('support.threads.index');
    Route::post('support/threads/{thread}/reply', [Admin\OpsController::class, 'replyThread'])->whereNumber('thread')->middleware('permission:support.reply')->name('support.threads.reply');
    Route::get('audit-logs', [Admin\OpsController::class, 'auditLogs'])->middleware('permission:audit.view')->name('audit-logs.index');
});
