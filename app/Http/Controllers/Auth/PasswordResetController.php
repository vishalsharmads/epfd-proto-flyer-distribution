<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordResetController extends Controller
{
    public function request(): Response
    {
        return Inertia::render('auth/forgot-password', ['status' => session('status')]);
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        self::sendLink($data['email']);

        // Same response whether or not the account exists (no user enumeration).
        return back()->with('status', 'If an account exists for that email, a reset link is on its way. Check your inbox.');
    }

    /**
     * Email a reset link if the account exists. The broker limits one link per account per minute
     * (auth.passwords.users.throttle). Shared by the web form and the API.
     */
    public static function sendLink(string $email): void
    {
        Password::sendResetLink(['email' => mb_strtolower(trim($email))], function (User $user, string $token) {
            $url = url(route('password.reset', ['token' => $token, 'email' => $user->email], false));
            try {
                Mail::to($user->email)->send(new ResetPasswordMail($user->name, $url));
            } catch (TransportExceptionInterface $e) {
                report($e);
            }
        });
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('auth/reset-password', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $credentials = [...$request->only('password', 'password_confirmation', 'token'), 'email' => mb_strtolower(trim((string) $request->input('email')))];
        $status = Password::reset($credentials, function (User $user, string $password) {
            // A new password hash signs out every other browser session (AuthenticateSession) and the
            // new remember token ends "remember me" logins; API tokens are revoked here.
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect()->route('login')->with('status', 'Your password has been reset. Sign in with your new password.');
    }
}
