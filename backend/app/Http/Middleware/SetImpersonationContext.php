<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Audit\AuditContext;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
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
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null && $request->bearerToken() !== null) {
            /** @var User|null $sanctumUser */
            $sanctumUser = auth('sanctum')->user();
            $user = $sanctumUser;
        }

        if ($user !== null) {
            /** @var mixed $currentToken */
            $currentToken = $user->currentAccessToken();
            if ($currentToken instanceof PersonalAccessToken && AuditContext::isImpersonatedToken($currentToken)) {
                $impersonatorId = $this->extractImpersonatorId($currentToken);
                if ($impersonatorId !== null) {
                    $this->auditContext->setImpersonatorId($impersonatorId);
                    $request->attributes->set('impersonator_id', $impersonatorId);
                    $request->attributes->set('is_impersonated', true);
                }
            }
        }

        $response = $next($request);

        // In case user was resolved during downstream request processing
        /** @var User|null $postUser */
        $postUser = $request->user();
        if ($this->auditContext->getImpersonatorId() === null && $postUser !== null) {
            /** @var mixed $currentToken */
            $currentToken = $postUser->currentAccessToken();
            if ($currentToken instanceof PersonalAccessToken && AuditContext::isImpersonatedToken($currentToken)) {
                $impersonatorId = $this->extractImpersonatorId($currentToken);
                if ($impersonatorId !== null) {
                    $this->auditContext->setImpersonatorId($impersonatorId);
                    $request->attributes->set('impersonator_id', $impersonatorId);
                    $request->attributes->set('is_impersonated', true);
                }
            }
        }

        return $response;
    }

    private function extractImpersonatorId(PersonalAccessToken $token): ?int
    {
        if (is_array($token->abilities)) {
            foreach ($token->abilities as $ability) {
                if (is_string($ability) && str_starts_with($ability, 'impersonator:')) {
                    $id = substr($ability, 13);
                    if (is_numeric($id)) {
                        return (int) $id;
                    }
                }
            }
        }

        if (isset($token->name) && str_starts_with($token->name, 'impersonation:')) {
            $id = substr($token->name, 14);
            if (is_numeric($id)) {
                return (int) $id;
            }
        }

        return null;
    }
}
