<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Map audience builder preview (owned by the map module). Wired from routes/web.php.
Route::middleware(['web', 'auth'])->get('/dev/map-preview', fn () => Inertia\Inertia::render('dev/map-preview'))->name('dev.map');

// Map engine POC: real audience builder with Terra Draw / MapLibre / Google switches; ?tab=compare shows the write-up.
Route::middleware(['web', 'auth'])->get('/dev/map-engines', fn (Request $request) => $request->query('tab') === 'compare'
    ? view('poc.map-engines')
    : Inertia\Inertia::render('dev/map-engines'))->name('dev.map-engines');
