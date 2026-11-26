<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DTO\Cast;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce absolute and idle session lifetimes for authenticated browser sessions.
 */
final class EnforceSessionLifetimes
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! Auth::guard('web')->check()) {
            return $next($request);
        }

        $session = $request->session();
        $now = now()->getTimestamp();

        $absoluteLifetimeMinutes = Cast::int(config('session.absolute_lifetime', 1440), 1440);
        $idleLifetimeMinutes = Cast::int(config('session.lifetime', 120), 120);

        $loginAt = Cast::intOrNull($session->get('login_at'));
        if ($loginAt === null || ($now - $loginAt) > ($absoluteLifetimeMinutes * 60)) {
            return $this->expireSession($request);
        }

        $lastActivityAt = Cast::intOrNull($session->get('last_activity_at'));
        if ($lastActivityAt === null) {
            $lastActivityAt = $now;
            $session->put('last_activity_at', $lastActivityAt);
        } elseif (($now - $lastActivityAt) > ($idleLifetimeMinutes * 60)) {
            return $this->expireSession($request);
        }

        if (! $this->isPassiveRequest($request)) {
            $session->put('last_activity_at', $now);
        }

        return $next($request);
    }

    /**
     * Determine whether the request is passive polling that must not extend idle lifetime.
     */
    private function isPassiveRequest(Request $request): bool
    {
        $activity = strtolower(Cast::string($request->header('X-Ognivo-Activity', '')));
        if ($activity !== 'passive') {
            return false;
        }

        return $request->isMethod('GET')
            || ($request->isMethod('POST') && $request->is('api/v1/broadcasting/auth'));
    }

    /**
     * Log out the web guard, invalidate the session, regenerate CSRF token, and return 401.
     */
    private function expireSession(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Session expired.'], 401);
    }
}
