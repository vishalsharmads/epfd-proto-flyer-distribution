<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Map audience builder preview (owned by the map module). Wired from routes/web.php.
Route::middleware(['web', 'auth'])->get('/dev/map-preview', fn () => Inertia\Inertia::render('dev/map-preview'))->name('dev.map');
