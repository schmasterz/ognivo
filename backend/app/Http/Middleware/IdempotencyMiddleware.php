<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cache and replay responses for mutating API requests carrying an Idempotency-Key header.
 */
final class IdempotencyMiddleware
{
    /**
     * Handle an incoming request and enforce idempotency guarantees when an Idempotency-Key header is present.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            return $next($request);
        }

        $checksum = hash(
            'sha256',
            $request->getMethod().'|'.$request->path().'|'.$request->getContent()
        );

        /** @var User|null $user */
        $user = $request->user();
        $userId = $user?->id;

        $query = IdempotencyKey::query()->where(['key' => $idempotencyKey]);
        if ($userId !== null) {
            $query->where('user_id', $userId);
        } else {
            $query->whereNull('user_id');
        }

        $record = $query->first();

        if ($record !== null) {
            if (! $record->matchesChecksum($checksum)) {
                return response()->json([
                    'message' => 'Idempotency key payload mismatch',
                ], 422);
            }

            if ($record->isLocked()) {
                return response()->json([
                    'message' => 'A request with this idempotency key is currently being processed.',
                ], 409);
            }

            if ($record->isCompleted()) {
                /** @var array<string, mixed> $headers */
                $headers = is_array($record->response_headers) ? $record->response_headers : [];
                $headers['X-Idempotent-Replay'] = 'true';

                return response(
                    $record->response_body ?? '',
                    (int) $record->response_status,
                    $headers
                );
            }

            // Re-acquire stale lock if a prior in-flight request never recorded a response.
            $record->update([
                'locked_at' => now(),
                'expires_at' => now()->addHours(24),
            ]);
        } else {
            $record = IdempotencyKey::query()->create([
                'user_id' => $userId,
                'key' => $idempotencyKey,
                'request_hash' => $checksum,
                'locked_at' => now(),
                'expires_at' => now()->addHours(24),
            ]);
        }

        $response = $next($request);
        $content = $response->getContent();

        $record->update([
            'response_status' => $response->getStatusCode(),
            'response_headers' => $response->headers->all(),
            'response_body' => ($content !== false && $content !== '') ? $content : null,
            'locked_at' => null,
        ]);

        return $response;
    }
}
