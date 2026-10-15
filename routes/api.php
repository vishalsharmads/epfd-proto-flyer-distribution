<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\GeoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| REST API v1  (prefix /api/v1, see bootstrap/app.php)
|--------------------------------------------------------------------------
| Auth: Sanctum. Bearer token for API clients; the web app calls the same endpoints with its
| session cookie (stateful SPA auth). Errors: RFC 9457 application/problem+json.
| Rate limits: api 120/min, estimate 240/min, auth 10/min/IP, payments 10/min.
*/

Route::middleware('throttle:api')->group(function () {
    // --- Public geo / pricing (no auth needed for quoting) ---------------------------------
    Route::get('suburbs', [GeoController::class, 'suburbs'])->name('api.suburbs.index');
    Route::get('suburbs/{suburb}/boundary', [GeoController::class, 'suburbBoundary'])->name('api.suburbs.boundary');
    Route::get('addresses/search', [GeoController::class, 'addressSearch'])->name('api.addresses.search');
    Route::get('addresses/viewport', [GeoController::class, 'viewport'])->name('api.addresses.viewport');
    Route::post('areas/estimate', [GeoController::class, 'estimate'])->middleware('throttle:estimate')->name('api.areas.estimate');
    Route::post('pricing/distribution/quote', [GeoController::class, 'distributionQuote'])->name('api.pricing.distribution');
    Route::post('pricing/print/quote', [GeoController::class, 'printQuote'])->name('api.pricing.print');

    // --- Remaining endpoints (auth, profile, campaigns, print, payments, support, admin,
    //     distributor stubs, webhooks) are registered in routes/api_v1.php.
    require __DIR__.'/api_v1.php';
});
