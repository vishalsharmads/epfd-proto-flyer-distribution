<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BusinessController;
use App\Http\Controllers\Admin\CampaignController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DesignOrderController;
use App\Http\Controllers\Admin\GnafController;
use App\Http\Controllers\Admin\GnafGuideController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PickupPointController;
use App\Http\Controllers\Admin\PrintCategoryController;
use App\Http\Controllers\Admin\PrintOrderController;
use App\Http\Controllers\Admin\PrintProductController;
use App\Http\Controllers\Admin\RateCardController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ServiceAreaController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
| Admin panel (/admin). Owned by the admin workstream. RBAC via 'staff' + permission:* middleware
| (super_admin passes every check via Gate::before). URLs match the sidebar in admin-layout.tsx.
*/
Route::middleware(['auth', 'staff'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    // Businesses
    Route::middleware('permission:businesses.view')->group(function () {
        Route::get('businesses', [BusinessController::class, 'index'])->name('businesses.index');
        Route::get('businesses/{business}', [BusinessController::class, 'show'])->name('businesses.show');
    });
    Route::post('businesses/{business}/status', [BusinessController::class, 'status'])->middleware('permission:businesses.manage')->name('businesses.status');
    Route::post('businesses/{business}/credits', [BusinessController::class, 'credit'])->middleware('permission:credits.manage')->name('businesses.credits');

    // Distribution campaigns + runs
    Route::middleware('permission:campaigns.view')->group(function () {
        Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
        Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
        Route::get('campaigns/{campaign}/proof-report', [CampaignController::class, 'proofFile'])->name('campaigns.proof');
    });
    Route::middleware('permission:campaigns.manage')->group(function () {
        Route::post('campaigns/{campaign}/runs', [CampaignController::class, 'split'])->name('campaigns.runs.split');
        Route::post('campaigns/{campaign}/runs/one-per-area', [CampaignController::class, 'onePerArea'])->name('campaigns.runs.one-per-area');
        Route::post('campaigns/{campaign}/runs/publish', [CampaignController::class, 'publish'])->name('campaigns.runs.publish');
        Route::post('campaigns/{campaign}/proof-report', [CampaignController::class, 'uploadProof'])->name('campaigns.proof.upload');
        Route::post('campaigns/{campaign}/proof-report/generate', [CampaignController::class, 'generateProof'])->name('campaigns.proof.generate');
        Route::patch('runs/{run}', [CampaignController::class, 'updateRun'])->name('runs.update');
        Route::delete('runs/{run}', [CampaignController::class, 'destroyRun'])->name('runs.destroy');
        Route::post('runs/{run}/assign', [CampaignController::class, 'assignRun'])->name('runs.assign');
        Route::post('runs/{run}/status', [CampaignController::class, 'transitionRun'])->name('runs.status');
        Route::post('runs/{run}/simulate-gps', [CampaignController::class, 'simulateRun'])->name('runs.simulate');
    });

    // Print orders
    Route::middleware('permission:print_orders.view')->group(function () {
        Route::get('print-orders', [PrintOrderController::class, 'index'])->name('print-orders.index');
        Route::get('print-orders/{printOrder}', [PrintOrderController::class, 'show'])->name('print-orders.show');
        Route::get('print-orders/{printOrder}/artworks/{artwork}/file', [PrintOrderController::class, 'artworkFile'])->name('print-orders.artwork');
        Route::get('print-orders/{printOrder}/proof', [PrintOrderController::class, 'proofFile'])->name('print-orders.proof');
    });
    Route::middleware('permission:print_orders.manage')->group(function () {
        Route::post('print-orders/{printOrder}/status', [PrintOrderController::class, 'transition'])->name('print-orders.status');
        Route::post('print-orders/{printOrder}/vendor', [PrintOrderController::class, 'vendor'])->name('print-orders.vendor');
        Route::post('print-orders/{printOrder}/proof', [PrintOrderController::class, 'uploadProof'])->name('print-orders.proof.upload');
        Route::post('print-orders/{printOrder}/artworks/{artwork}/review', [PrintOrderController::class, 'reviewArtwork'])->name('print-orders.artwork.review');
    });

    // Design Studio orders
    Route::middleware('permission:design_orders.view')->group(function () {
        Route::get('design-orders', [DesignOrderController::class, 'index'])->name('design-orders.index');
        Route::get('design-orders/{designOrder}', [DesignOrderController::class, 'show'])->name('design-orders.show');
        Route::get('design-orders/{designOrder}/assets/{asset}', [DesignOrderController::class, 'asset'])->name('design-orders.asset');
    });
    Route::middleware('permission:design_orders.manage')->group(function () {
        Route::post('design-orders/{designOrder}/assign', [DesignOrderController::class, 'assign'])->name('design-orders.assign');
        Route::post('design-orders/{designOrder}/status', [DesignOrderController::class, 'transition'])->name('design-orders.status');
        Route::post('design-orders/{designOrder}/proofs', [DesignOrderController::class, 'uploadProof'])->name('design-orders.proofs');
        Route::post('design-orders/{designOrder}/finals', [DesignOrderController::class, 'uploadFinal'])->name('design-orders.finals');
        Route::post('design-orders/{designOrder}/messages', [DesignOrderController::class, 'message'])->name('design-orders.messages');
    });

    // Pickup points & service areas
    Route::middleware('permission:pickup_points.manage')->group(function () {
        Route::get('pickup-points', [PickupPointController::class, 'index'])->name('pickup-points.index');
        Route::post('pickup-points', [PickupPointController::class, 'store'])->name('pickup-points.store');
        Route::put('pickup-points/{pickupPoint}', [PickupPointController::class, 'update'])->name('pickup-points.update');
        Route::delete('pickup-points/{pickupPoint}', [PickupPointController::class, 'destroy'])->name('pickup-points.destroy');
    });
    Route::middleware('permission:service_areas.manage')->group(function () {
        Route::get('service-areas', [ServiceAreaController::class, 'index'])->name('service-areas.index');
        Route::get('service-areas/{suburb}', [ServiceAreaController::class, 'show'])->name('service-areas.show');
        Route::post('service-areas/{suburb}/serviced', [ServiceAreaController::class, 'toggle'])->name('service-areas.toggle');
        Route::post('service-areas/{suburb}/recompute', [ServiceAreaController::class, 'recompute'])->name('service-areas.recompute');
    });

    // Catalogue: print products + categories (prices: Rate card → Print matrix)
    Route::middleware('permission:catalogue.manage')->prefix('catalogue')->name('catalogue.')->group(function () {
        Route::get('print', [PrintProductController::class, 'index'])->name('print.index');
        Route::get('print/create', [PrintProductController::class, 'create'])->name('print.create');
        Route::post('print', [PrintProductController::class, 'store'])->name('print.store');
        Route::get('print/{product}/edit', [PrintProductController::class, 'edit'])->name('print.edit');
        Route::put('print/{product}', [PrintProductController::class, 'update'])->name('print.update');
        Route::post('print/{product}/toggle', [PrintProductController::class, 'toggle'])->name('print.toggle');
        Route::delete('print/{product}', [PrintProductController::class, 'destroy'])->name('print.destroy');
        Route::post('categories', [PrintCategoryController::class, 'store'])->name('categories.store');
        Route::put('categories/{category}', [PrintCategoryController::class, 'update'])->name('categories.update');
        Route::delete('categories/{category}', [PrintCategoryController::class, 'destroy'])->name('categories.destroy');
    });

    // Rate card, print matrix, promos
    Route::middleware('permission:rate_card.manage')->group(function () {
        Route::get('rate-card', [RateCardController::class, 'show'])->name('rate-card.show');
        Route::post('rate-card', [RateCardController::class, 'store'])->name('rate-card.store');
        Route::post('rate-card/preview', [RateCardController::class, 'preview'])->name('rate-card.preview');
        Route::post('rate-card/{rateCard}/activate', [RateCardController::class, 'activate'])->name('rate-card.activate');
        Route::put('rate-card/matrix/{product}', [RateCardController::class, 'matrix'])->name('rate-card.matrix');
        Route::post('promos', [RateCardController::class, 'storePromo'])->name('promos.store');
        Route::put('promos/{promo}', [RateCardController::class, 'updatePromo'])->name('promos.update');
        Route::delete('promos/{promo}', [RateCardController::class, 'destroyPromo'])->name('promos.destroy');
    });

    // Payments & invoices
    Route::middleware('permission:payments.view')->group(function () {
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/export', [PaymentController::class, 'exportPayments'])->name('payments.export');
        Route::get('invoices/export', [PaymentController::class, 'exportInvoices'])->name('invoices.export');
        Route::get('invoices/{invoice}/pdf', [PaymentController::class, 'invoicePdf'])->name('invoices.pdf');
    });
    Route::post('payments/{payment}/refund', [PaymentController::class, 'refund'])->middleware('permission:payments.refund')->name('payments.refund');

    // Reports
    Route::middleware('permission:reports.view')->group(function () {
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/{report}/export', [ReportController::class, 'export'])->name('reports.export');
    });

    // Support inbox
    Route::middleware('permission:support.view')->group(function () {
        Route::get('support', [SupportController::class, 'index'])->name('support.index');
        Route::get('support/{thread}', [SupportController::class, 'show'])->name('support.show');
    });
    Route::middleware('permission:support.reply')->group(function () {
        Route::post('support/{thread}/reply', [SupportController::class, 'reply'])->name('support.reply');
        Route::patch('support/{thread}', [SupportController::class, 'update'])->name('support.update');
    });

    // Content
    Route::middleware('permission:content.manage')->group(function () {
        Route::get('content', [ContentController::class, 'index'])->name('content.index');
        Route::post('content', [ContentController::class, 'store'])->name('content.store');
        Route::put('content/{page}', [ContentController::class, 'update'])->name('content.update');
        Route::delete('content/{page}', [ContentController::class, 'destroy'])->name('content.destroy');
    });

    Route::get('gnaf', GnafController::class)->middleware('permission:gnaf.view')->name('gnaf');
    Route::get('gnaf/guide', GnafGuideController::class)->middleware('permission:gnaf.view')->name('gnaf.guide');

    Route::middleware('permission:users.manage')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::post('users/{user}/role', [UserController::class, 'role'])->name('users.role');
        Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    });

    Route::get('audit-log', AuditLogController::class)->middleware('permission:audit.view')->name('audit-log');

    // App settings (maps, walking limit, estimator)
    Route::middleware('permission:settings.manage')->group(function () {
        Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    });
});
