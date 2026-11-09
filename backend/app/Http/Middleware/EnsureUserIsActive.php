<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware ensuring the authenticated user holds an active account status.
 */
final class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->status !== 'active') {
            $msgKey = match ($user->status) {
                'suspended' => 'messages.auth.account_suspended',
                'inactive' => 'messages.auth.account_inactive',
                'pending' => 'messages.auth.account_pending_verification',
                default => 'messages.auth.account_inactive',
            };

            $message = __($msgKey);

            abort(403, is_string($message) ? $message : 'Your account is not active.');
        }

        return $next($request);
    }
}
