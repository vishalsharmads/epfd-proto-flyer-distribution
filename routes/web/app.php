<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
| Business portal (/app). Owned by the business-portal workstream.
*/
Route::middleware(['auth', 'business'])->prefix('app')->name('app.')->group(function () {
    Route::get('/', fn () => Inertia::render('app/dashboard'))->name('dashboard');
});
