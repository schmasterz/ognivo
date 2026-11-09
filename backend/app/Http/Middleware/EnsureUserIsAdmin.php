<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware ensuring the authenticated user holds the administrator role.
 */
final class EnsureUserIsAdmin
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

        if ($user === null || ! $user->hasRole('admin')) {
            $message = __('messages.vm.unauthorized_access');
            abort(403, is_string($message) ? $message : 'Unauthorized access.');
        }

        return $next($request);
    }
}
