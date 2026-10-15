<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LegalController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

/*
| Registration, email OTP verification, password reset and public legal pages.
*/
Route::middleware('guest')->group(function () {
    Route::get('register', [RegisterController::class, 'show'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:auth')->name('register.store');
    Route::get('register/abn', [RegisterController::class, 'abn'])->middleware('throttle:auth')->name('register.abn');
    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:auth')->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:auth')->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::get('verify', [OtpController::class, 'show'])->name('verify.otp');
    Route::post('verify', [OtpController::class, 'verify'])->middleware('throttle:auth')->name('verify.otp.check');
    Route::post('verify/resend', [OtpController::class, 'resend'])->name('verify.otp.resend');
});

Route::get('legal/{slug}', [LegalController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('legal.show');
