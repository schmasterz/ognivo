<?php

use App\Http\Middleware\EnforceSessionLifetimes;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\ForbidImpersonationActions;
use App\Http\Middleware\SetAuditContext;
use App\Http\Middleware\SetImpersonationContext;
use App\Http\Middleware\SetLocaleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = env('TRUSTED_PROXIES');
        /** @var array<int, string>|string $at */
        $at = match (true) {
            is_string($trustedProxies) && trim($trustedProxies) === '*' => '*',
            is_string($trustedProxies) && trim($trustedProxies) !== '' => array_values(array_filter(array_map('trim', explode(',', $trustedProxies)))),
            is_array($trustedProxies) => array_values(array_filter(array_map('strval', $trustedProxies))),
            default => [
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
                '127.0.0.1',
            ],
        };
        $middleware->trustProxies(at: $at);

        $middleware->statefulApi();
        $middleware->preventRequestForgery(originOnly: false, allowSameSite: false);

        $middleware->redirectGuestsTo(fn (Request $request) => null);

        $middleware->append(SetLocaleMiddleware::class);
        $middleware->api(append: [
            AuthenticateSession::class,
            EnforceSessionLifetimes::class,
            SetImpersonationContext::class,
            SetAuditContext::class,
            ForbidImpersonationActions::class,
        ]);
        $middleware->prependToPriorityList(SubstituteBindings::class, AuthenticateSession::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnforceSessionLifetimes::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, SetImpersonationContext::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, SetAuditContext::class);
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'active' => EnsureUserIsActive::class,
            'impersonation' => SetImpersonationContext::class,
        ]);
    })
    ->withBindings([
        EnsureFrontendRequestsAreStateful::class => static fn (): EnsureFrontendRequestsAreStateful => new class extends EnsureFrontendRequestsAreStateful
        {
            /**
             * Preserve configured session SameSite policy instead of Sanctum's hardcoded 'lax' override.
             */
            protected function configureSecureCookieSessions(): void
            {
                $sameSite = config('session.same_site', 'strict');

                config([
                    'session.http_only' => true,
                    'session.same_site' => is_string($sameSite) && $sameSite !== '' ? $sameSite : 'strict',
                ]);
            }
        },
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
