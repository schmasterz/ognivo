<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Audit\AuditContext;
use App\Models\ServiceToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware authenticating incoming requests bearing a service token (`Bearer srv_<secret>`).
 */
final class AuthenticateServiceToken
{
    private const string TOKEN_PREFIX = 'srv_';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $bearerToken = $request->bearerToken() ?? '';

        if (! str_starts_with($bearerToken, self::TOKEN_PREFIX)) {
            if ($mode === 'required') {
                return $this->unauthenticated();
            }

            return $next($request);
        }

        $secret = substr($bearerToken, strlen(self::TOKEN_PREFIX));
        if ($secret === '') {
            return $this->unauthenticated();
        }

        $hash = hash('sha256', $secret);

        /** @var ServiceToken|null $token */
        $token = ServiceToken::query()->where('token_hash', $hash)->first();

        if (! $token instanceof ServiceToken || ! $token->isValid()) {
            return $this->unauthenticated();
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('service_token', $token);
        app()->instance(ServiceToken::class, $token);

        if (app()->bound(AuditContext::class)) {
            /** @var AuditContext $auditContext */
            $auditContext = app(AuditContext::class);
            $auditContext->forServiceToken($token);
        }

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
