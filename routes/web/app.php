<?php

declare(strict_types=1);

use App\Http\Controllers\App\AccountController;
use App\Http\Controllers\App\CampaignController;
use App\Http\Controllers\App\CampaignWizardController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\DesignOrderController;
use App\Http\Controllers\App\DocumentController;
use App\Http\Controllers\App\JobController;
use App\Http\Controllers\App\PrintOrderController;
use App\Http\Controllers\App\SupportController;
use Illuminate\Support\Facades\Route;

/*
| Business portal (/app). Owned by the business-portal workstream.
| Every model-bound route is authorised against the user's business via CampaignPolicy / PrintOrderPolicy / DesignOrderPolicy.
*/
Route::middleware(['auth', 'business'])->prefix('app')->name('app.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    // Letterbox distribution hub + campaign wizard (Campaign → Audience → Details → Confirm)
    Route::get('distribution', [CampaignController::class, 'hub'])->name('distribution');
    Route::get('campaigns/new', [CampaignWizardController::class, 'start'])->name('campaigns.start');
    Route::get('campaigns/name-check', [CampaignWizardController::class, 'nameCheck'])->name('campaigns.name-check');
    Route::post('campaigns', [CampaignWizardController::class, 'store'])->name('campaigns.store');
    Route::post('campaigns/{campaign}/rerun', [CampaignWizardController::class, 'rerun'])->name('campaigns.rerun');
    Route::delete('campaigns/{campaign}', [CampaignWizardController::class, 'destroy'])->name('campaigns.destroy');
    Route::put('campaigns/{campaign}/areas', [CampaignWizardController::class, 'syncAreas'])->name('campaigns.areas');
    Route::patch('campaigns/{campaign}', [CampaignWizardController::class, 'update'])->name('campaigns.update');
    Route::post('campaigns/{campaign}/pay', [CampaignWizardController::class, 'pay'])->middleware('throttle:payments')->name('campaigns.pay');
    Route::get('campaigns/{campaign}/{step}', [CampaignWizardController::class, 'step'])->whereIn('step', ['audience', 'details', 'confirm'])->name('campaigns.step');

    // Print store + print wizard (Start → Product → Specs → Confirm)
    Route::get('print', [PrintOrderController::class, 'hub'])->name('print');
    Route::get('print/new', [PrintOrderController::class, 'start'])->name('print.start');
    Route::post('print', [PrintOrderController::class, 'store'])->name('print.store');
    Route::post('print/{printOrder}/reorder', [PrintOrderController::class, 'reorder'])->name('print.reorder');
    Route::delete('print/{printOrder}', [PrintOrderController::class, 'destroy'])->name('print.destroy');
    Route::patch('print/{printOrder}', [PrintOrderController::class, 'update'])->name('print.update');
    Route::post('print/{printOrder}/artworks', [PrintOrderController::class, 'upload'])->name('print.artworks.store');
    Route::delete('print/{printOrder}/artworks/{artwork}', [PrintOrderController::class, 'deleteArtwork'])->name('print.artworks.destroy');
    Route::get('print/{printOrder}/artworks/{artwork}', [PrintOrderController::class, 'artwork'])->name('print.artworks.show');
    Route::post('print/{printOrder}/pay', [PrintOrderController::class, 'pay'])->middleware('throttle:payments')->name('print.pay');
    Route::get('print/{printOrder}/{step}', [PrintOrderController::class, 'step'])->whereIn('step', ['product', 'specs', 'confirm'])->name('print.step');

    // Design Studio hub + design wizard (Start → Service → Brief → Confirm) and design job actions
    Route::get('design', [DesignOrderController::class, 'hub'])->name('design');
    Route::get('design/new', [DesignOrderController::class, 'start'])->name('design.start');
    Route::post('design', [DesignOrderController::class, 'store'])->name('design.store');
    Route::delete('design/{designOrder}', [DesignOrderController::class, 'destroy'])->name('design.destroy');
    Route::patch('design/{designOrder}', [DesignOrderController::class, 'update'])->name('design.update');
    Route::post('design/{designOrder}/assets', [DesignOrderController::class, 'upload'])->name('design.assets.store');
    Route::delete('design/{designOrder}/assets/{asset}', [DesignOrderController::class, 'deleteAsset'])->name('design.assets.destroy');
    Route::get('design/{designOrder}/assets/{asset}', [DesignOrderController::class, 'asset'])->name('design.assets.show');
    Route::post('design/{designOrder}/pay', [DesignOrderController::class, 'pay'])->middleware('throttle:payments')->name('design.pay');
    Route::post('design/{designOrder}/approve', [DesignOrderController::class, 'approve'])->name('design.approve');
    Route::post('design/{designOrder}/request-changes', [DesignOrderController::class, 'requestChanges'])->name('design.request-changes');
    Route::post('design/{designOrder}/print', [DesignOrderController::class, 'print'])->name('design.print');
    Route::get('design/{designOrder}/{step}', [DesignOrderController::class, 'step'])->whereIn('step', ['service', 'brief', 'confirm'])->name('design.step');

    // My jobs
    Route::get('jobs', [JobController::class, 'index'])->name('jobs');
    Route::get('jobs/campaigns/{campaign}', [JobController::class, 'campaign'])->name('jobs.campaign');
    Route::get('jobs/print/{printOrder}', [JobController::class, 'print'])->name('jobs.print');
    Route::get('jobs/design/{designOrder}', [JobController::class, 'design'])->name('jobs.design');
    Route::post('jobs/{type}/{id}/messages', [JobController::class, 'message'])->whereIn('type', ['campaign', 'print', 'design'])->whereNumber('id')->name('jobs.messages');
    Route::post('jobs/{type}/{id}/rating', [JobController::class, 'rate'])->whereIn('type', ['campaign', 'print'])->whereNumber('id')->name('jobs.rating');
    Route::post('jobs/campaigns/{campaign}/areas/{area}/save', [JobController::class, 'saveArea'])->name('jobs.save-area');

    // Documents (PDF)
    Route::get('invoices/{invoice}/pdf', [DocumentController::class, 'invoice'])->name('invoices.pdf');
    Route::get('jobs/campaigns/{campaign}/proof.pdf', [DocumentController::class, 'proof'])->name('jobs.proof');

    // Account
    Route::get('account', [AccountController::class, 'show'])->name('account');
    Route::put('account/profile', [AccountController::class, 'profile'])->name('account.profile');
    Route::put('account/password', [AccountController::class, 'password'])->name('account.password');
    Route::delete('account/cards/{card}', [AccountController::class, 'deleteCard'])->name('account.cards.destroy');
    Route::post('account/cards/{card}/default', [AccountController::class, 'defaultCard'])->name('account.cards.default');
    Route::delete('account/areas/{area}', [AccountController::class, 'deleteArea'])->name('account.areas.destroy');

    // Support
    Route::get('support', [SupportController::class, 'index'])->name('support');
    Route::post('support', [SupportController::class, 'store'])->name('support.store');
    Route::get('support/{thread}', [SupportController::class, 'show'])->name('support.show');
    Route::post('support/{thread}/messages', [SupportController::class, 'reply'])->name('support.reply');
});
