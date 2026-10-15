<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Mail\OtpCodeMail;
use App\Mail\ResetPasswordMail;
use App\Models\Business;
use App\Models\User;
use App\Rules\Abn;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * @tags Auth
 */
class AuthController extends Controller
{
    private const OTP_TTL_MINUTES = 10;

    private const OTP_MAX_ATTEMPTS = 5;

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
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'contact_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'mobile' => ['required', 'string', 'regex:/^(\+?61|0)4\d{8}$/'],
            'abn' => ['nullable', 'string', new Abn],
            'industry' => ['nullable', 'string', 'max:80'],
            'address' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', PasswordRule::min(8)],
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

        $this->sendOtp($user);
        Audit::log('business.registered', $user->business);

        return response()->json([
            'token' => $user->createToken($data['device_name'] ?? 'api')->plainTextToken,
            'user' => UserResource::make($user->load('business')),
            'otp_required' => true,
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

    /** Verify the 6-digit e-mail OTP (max 5 attempts, valid 10 minutes). */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        /** @var User $user */
        $user = $request->user();
        if ($user->email_verified_at) {
            return response()->json(['message' => 'Already verified.', 'verified' => true]);
        }
        if (! $user->otp_hash || ! $user->otp_expires_at || $user->otp_expires_at->isPast() || $user->otp_attempts >= self::OTP_MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['code' => ['The code has expired. Request a new one.']]);
        }
        if (! Hash::check($data['code'], $user->otp_hash)) {
            $user->increment('otp_attempts');
            throw ValidationException::withMessages(['code' => ['The code is incorrect.']]);
        }
        $user->forceFill(['email_verified_at' => now(), 'otp_hash' => null, 'otp_expires_at' => null, 'otp_attempts' => 0])->save();

        return response()->json(['message' => 'E-mail verified.', 'verified' => true]);
    }

    /** Send a fresh OTP to the user's e-mail. */
    public function resendOtp(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->email_verified_at) {
            $this->sendOtp($user);
        }

        return response()->json(['message' => 'If verification is pending, a new code has been sent.']);
    }

    /**
     * Request a password-reset e-mail. Always returns 200 (no account enumeration).
     *
     * @unauthenticated
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', strtolower($data['email']))->first();
        if ($user) {
            $token = Password::broker()->createToken($user);
            $url = url('/reset-password/'.$token.'?email='.urlencode($user->email));
            rescue(fn () => Mail::to($user->email)->send(new ResetPasswordMail($user->name, $url)), report: true);
        }

        return response()->json(['message' => 'If that e-mail is registered, a reset link has been sent.']);
    }

    private function sendOtp(User $user): void
    {
        $code = (string) random_int(100000, 999999);
        $user->forceFill([
            'otp_hash' => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
            'otp_attempts' => 0,
        ])->save();
        try {
            Mail::to($user->email)->send(new OtpCodeMail($user->name, $code));
        } catch (Throwable $e) {
            report($e);
            Mail::raw("Your EPFD verification code is {$code}. It expires in ".self::OTP_TTL_MINUTES.' minutes.', fn ($m) => $m->to($user->email)->subject('Your EPFD verification code'));
        }
    }
}
