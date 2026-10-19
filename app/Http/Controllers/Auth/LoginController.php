<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('auth/login', [
            'admin' => $request->routeIs('admin.login'),
            'status' => session('status'),
            // Seeded demo accounts (password "password") — never exposed in production.
            'demoAccounts' => app()->isProduction() ? [] : [
                ['email' => 'demo@business.test', 'label' => 'Business owner', 'hint' => 'Harbour Coffee Co. · approved · NSW', 'admin' => false],
                ['email' => 'vic@business.test', 'label' => 'Business (VIC)', 'hint' => 'Approved · Richmond', 'admin' => false],
                ['email' => 'pending@business.test', 'label' => 'Pending business', 'hint' => 'Awaiting approval — payment blocked', 'admin' => false],
                ['email' => 'admin@epfd.test', 'label' => 'Super admin', 'hint' => 'Everything', 'admin' => true],
                ['email' => 'ops@epfd.test', 'label' => 'Operations', 'hint' => 'Businesses, campaigns, runs, print', 'admin' => true],
                ['email' => 'finance@epfd.test', 'label' => 'Finance', 'hint' => 'Payments, refunds, rate card', 'admin' => true],
                ['email' => 'support@epfd.test', 'label' => 'Support', 'hint' => 'Inbox, content', 'admin' => true],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);

        if (! Auth::attempt(['email' => mb_strtolower(trim($credentials['email'])), 'password' => $credentials['password']], (bool) ($credentials['remember'] ?? false))) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        $user = $request->user();
        if (data_get($user->preferences, 'deactivated_at')) {
            Auth::guard('web')->logout();
            throw ValidationException::withMessages(['email' => 'This account has been deactivated. Contact EPFD support.']);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        if ($user->isStaff()) {
            return redirect()->intended(route('admin.dashboard'));
        }

        return redirect()->intended(route('app.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
