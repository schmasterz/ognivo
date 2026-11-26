<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Audit\AuditContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class SetImpersonationContext
{
    public function __construct(
        private readonly AuditContext $auditContext,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->hasExpiredImpersonationSession($request)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Impersonation session expired.'], 401);
        }

        $id = $this->auditContext->impersonatorIdFromRequest($request);
        if ($id !== null) {
            $this->auditContext->setImpersonatorId($id);
            $request->attributes->set('impersonator_id', $id);
            $request->attributes->set('is_impersonated', true);
        }

        return $next($request);
    }

    /**
     * Determine whether the request session contains an expired or incomplete impersonation state.
     */
    private function hasExpiredImpersonationSession(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $session = $request->session();
        $hasImpersonator = $session->has('impersonator_id');
        $hasExpiresAt = $session->has('impersonation_expires_at');

        if (! $hasImpersonator && ! $hasExpiresAt) {
            return false;
        }

        $expiresAt = $session->get('impersonation_expires_at');
        if (! is_int($expiresAt) && ! (is_string($expiresAt) && ctype_digit($expiresAt))) {
            return true;
        }

        return (int) $expiresAt <= time();
    }
}
