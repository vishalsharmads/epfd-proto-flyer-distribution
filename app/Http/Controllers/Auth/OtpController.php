<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\OtpCodeMail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/** Email OTP: 6 digits, hashed, 10-minute expiry, 5 attempts, resend throttled to once per 60 s. */
class OtpController extends Controller
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    /**
     * Create a new code and email it. Returns false when the email could not be sent (the code is still
     * saved, so a later resend works); the failure is reported to error tracking.
     */
    public static function issue(User $user): bool
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $user->forceFill([
            'otp_hash' => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'otp_attempts' => 0,
        ])->save();
        RateLimiter::hit(self::resendKey($user), self::RESEND_SECONDS);
        try {
            Mail::to($user->email)->send(new OtpCodeMail($user->name, $code));
        } catch (TransportExceptionInterface $e) {
            report($e);

            return false;
        }

        return true;
    }

    /** Seconds until another code may be sent, or 0. */
    public static function resendWait(User $user): int
    {
        return RateLimiter::tooManyAttempts(self::resendKey($user), 1) ? RateLimiter::availableIn(self::resendKey($user)) : 0;
    }

    private static function resendKey(User $user): string
    {
        return 'otp-resend:'.$user->id;
    }

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if ($user->email_verified_at !== null) {
            return redirect()->route('app.dashboard');
        }

        return Inertia::render('auth/verify-otp', [
            'email' => $user->email,
            'resend_in' => self::resendWait($user),
            'expires_at' => $user->otp_expires_at?->toIso8601String(),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']], ['code.digits' => 'Enter the 6-digit code from the email.']);
        /** @var User $user */
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return redirect()->route('app.dashboard');
        }
        if ($user->otp_hash === null || $user->otp_expires_at === null || $user->otp_expires_at->isPast()) {
            throw ValidationException::withMessages(['code' => 'This code has expired. Send a new code.']);
        }
        if ($user->otp_attempts >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['code' => 'Too many incorrect attempts. Send a new code.']);
        }
        if (! Hash::check($data['code'], $user->otp_hash)) {
            $user->increment('otp_attempts');
            $left = self::MAX_ATTEMPTS - $user->otp_attempts;
            throw ValidationException::withMessages(['code' => $left > 0 ? "That code isn't right. {$left} ".($left === 1 ? 'attempt' : 'attempts').' left.' : 'Too many incorrect attempts. Send a new code.']);
        }

        $user->forceFill(['email_verified_at' => now(), 'otp_hash' => null, 'otp_expires_at' => null, 'otp_attempts' => 0])->save();

        return redirect()->route('app.dashboard')->with('success', 'Email verified — welcome to EPFD!');
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->email_verified_at !== null) {
            return redirect()->route('app.dashboard');
        }
        if ($wait = self::resendWait($user)) {
            throw ValidationException::withMessages(['code' => "Please wait {$wait} seconds before requesting another code."]);
        }
        if (! self::issue($user)) {
            throw ValidationException::withMessages(['code' => "We couldn't send the email just now. Please try again in a minute."]);
        }

        return back()->with('success', 'A new code is on its way.');
    }
}
