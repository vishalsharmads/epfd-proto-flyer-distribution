<?php

declare(strict_types=1);

use App\Exceptions\DomainProblem;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureBusinessUser;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ProblemJson;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Readiness check (no session or cookies): database, cache, storage, queue, payment gateway.
            Route::get('health', HealthController::class)->middleware('throttle:60,1')->name('health');
            Route::middleware('web')->group(base_path('routes/web/app.php'));
            Route::middleware('web')->group(base_path('routes/web/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            // Signs out sessions whose password changed elsewhere (password reset, deactivation).
            AuthenticateSession::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->append(SecurityHeaders::class);
        $middleware->statefulApi();
        $middleware->alias([
            'staff' => EnsureStaff::class,
            'business' => EnsureBusinessUser::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
        $middleware->redirectGuestsTo(fn (Request $r) => $r->is('admin*') ? route('admin.login') : route('login'));
        $middleware->redirectUsersTo(fn (Request $r) => $r->user()?->isStaff() ? route('admin.dashboard') : route('app.dashboard'));
        $middleware->validateCsrfTokens(except: ['api/v1/webhooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions); // Sentry, when SENTRY_LARAVEL_DSN is set
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia')),
        );
        // RFC 9457 problem+json for every JSON error response.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia')))) {
                return null;
            }

            return ProblemJson::fromThrowable($e, $request);
        });
        $exceptions->dontReport([DomainProblem::class]);
    })->create();
