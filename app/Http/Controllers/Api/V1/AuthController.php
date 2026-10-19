<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainProblem;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Business;
use App\Models\User;
use App\Rules\Abn;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * @tags Auth
 */
class AuthController extends Controller
{
    /**
     * Register a business + owner user.
     *
     * The business starts as `pending` (drafts allowed; payment blocked until approved). A 6-digit
     * e-mail OTP is sent; verify it with `POST /auth/otp/verify`. Returns a Sanctum token.
     *
     * @unauthenticated
     */
    public function register(Request $request): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'contact_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'mobile' => ['required', 'string', 'regex:/^(\+?61|0)4\d{8}$/'],
            'abn' => ['nullable', 'string', new Abn],
            'industry' => ['nullable', 'string', 'max:80'],
            'address' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', PasswordRule::defaults()],
            'device_name' => ['nullable', 'string', 'max:100'],
            'accept_terms' => ['accepted'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $business = Business::create([
                'name' => $data['business_name'],
                'contact_name' => $data['contact_name'],
                'email' => strtolower($data['email']),
                'mobile' => $data['mobile'],
                'abn' => isset($data['abn']) ? preg_replace('/\D/', '', $data['abn']) : null,
                'industry' => $data['industry'] ?? null,
                'address' => $data['address'],
                'status' => Business::PENDING,
            ]);

            return User::create([
                'business_id' => $business->id,
                'name' => $data['contact_name'],
                'email' => strtolower($data['email']),
                'mobile' => $data['mobile'],
                'password' => $data['password'],
            ]);
        });

        $sent = OtpController::issue($user);
        Audit::log('business.registered', $user->business);

        return response()->json([
            'token' => $user->createToken($data['device_name'] ?? 'api')->plainTextToken,
            'user' => UserResource::make($user->load('business')),
            'otp_required' => true,
            'otp_sent' => $sent,
        ], 201);
    }

    /**
     * Log in and receive a Bearer token.
     *
     * @unauthenticated
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['These credentials do not match our records.']]);
        }
        if (data_get($user->preferences, 'deactivated_at')) {
            throw ValidationException::withMessages(['email' => ['This account has been deactivated. Contact EPFD support.']]);
        }
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $user->createToken($data['device_name'])->plainTextToken,
            'user' => UserResource::make($user->load('business')),
        ]);
    }

    /** Revoke the current token (or end the web session). */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        } elseif ($request->hasSession()) {
            auth('web')->logout();
            $request->session()->invalidate();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    /** The authenticated user with their business. */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => UserResource::make($request->user()->load('business'))]);
    }

    /** Verify the 6-digit e-mail OTP (max 5 attempts per code, valid 10 minutes). */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        /** @var User $user */
        $user = $request->user();
        if ($user->email_verified_at) {
            return response()->json(['message' => 'Already verified.', 'verified' => true]);
        }
        if (! $user->otp_hash || ! $user->otp_expires_at || $user->otp_expires_at->isPast() || $user->otp_attempts >= OtpController::MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['code' => ['The code has expired. Request a new one.']]);
        }
        if (! Hash::check($data['code'], $user->otp_hash)) {
            $user->increment('otp_attempts');
            throw ValidationException::withMessages(['code' => ['The code is incorrect.']]);
        }
        $user->forceFill(['email_verified_at' => now(), 'otp_hash' => null, 'otp_expires_at' => null, 'otp_attempts' => 0])->save();

        return response()->json(['message' => 'E-mail verified.', 'verified' => true]);
    }

    /** Send a fresh OTP to the user's e-mail. At most one per minute: 429 `rate_limited` with `retry_after` otherwise. */
    public function resendOtp(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->email_verified_at) {
            return response()->json(['message' => 'Already verified.', 'verified' => true]);
        }
        if ($wait = OtpController::resendWait($user)) {
            throw new DomainProblem('rate_limited', "Please wait {$wait} seconds before requesting another code.", 429, 'Too many requests', ['retry_after' => $wait]);
        }
        if (! OtpController::issue($user)) {
            throw new DomainProblem('mail_unavailable', "We couldn't send the email just now. Try again in a minute.", 503, 'Email unavailable');
        }

        return response()->json(['message' => 'A new code has been sent.']);
    }

    /**
     * Request a password-reset e-mail. Always returns 200 (no account enumeration).
     *
     * @unauthenticated
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        PasswordResetController::sendLink($data['email']);

        return response()->json(['message' => 'If that e-mail is registered, a reset link has been sent.']);
    }
}
