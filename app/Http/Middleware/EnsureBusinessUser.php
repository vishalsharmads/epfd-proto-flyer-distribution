<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Business portal: user must belong to a business and have verified their email (OTP). */
class EnsureBusinessUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_if($user === null || $user->business_id === null, 403, 'A business account is required.');

        if ($user->email_verified_at === null && ! $request->is('api/*')) {
            return redirect()->route('verify.otp');
        }

        return $next($request);
    }
}
