<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Audit\AuditContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetAuditContext
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
        $this->auditContext->fromRequest($request);

        $response = $next($request);

        // Update context if user was authenticated downstream during request handling
        if ($this->auditContext->getActorId() === null && $request->user() !== null) {
            $this->auditContext->fromRequest($request);
        }

        return $response;
    }
}
