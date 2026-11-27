<?php

declare(strict_types=1);

namespace App\Audit;

use App\Support\Cast;
use Closure;

final class RestoreAuditContext
{
    /**
     * Process the queued job and restore the audit context.
     */
    public function handle(mixed $job, Closure $next): mixed
    {
        if (is_object($job) && method_exists($job, 'payload')) {
            $rawPayload = $job->payload();
            if (is_array($rawPayload) && isset($rawPayload['audit_context']) && is_array($rawPayload['audit_context'])) {
                /** @var array<string, mixed> $contextPayload */
                $contextPayload = [];
                foreach ($rawPayload['audit_context'] as $k => $v) {
                    $contextPayload[Cast::string($k)] = $v;
                }
                app(AuditContext::class)->fromPayload($contextPayload, isJob: true);
            }
        }

        return $next($job);
    }
}
