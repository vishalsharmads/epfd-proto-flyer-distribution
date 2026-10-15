<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
| Public + auth routes. Business portal: routes/web/app.php. Admin: routes/web/admin.php.
*/

Route::get('/', fn () => Inertia::render('welcome'))->name('home');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::get('admin/login', [LoginController::class, 'show'])->name('admin.login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:auth')->name('login.store');
});

Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// Auth extensions (register, OTP verification, forgot/reset password) and legal pages: routes/web/auth.php
if (file_exists(__DIR__.'/web/auth.php')) {
    require __DIR__.'/web/auth.php';
}
// API docs (/docs, /docs/openapi.json): routes/web/docs.php
if (file_exists(__DIR__.'/web/docs.php')) {
    require __DIR__.'/web/docs.php';
}
