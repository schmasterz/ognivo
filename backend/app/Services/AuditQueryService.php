<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\Audit\AuditFilterDTO;
use App\DTO\Cast;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Service managing audit log querying, filtering, and keyset cursor pagination.
 */
final class AuditQueryService
{
    /**
     * Build an Eloquent query applying all filter criteria.
     *
     * @return Builder<AuditLog>
     */
    public function buildQuery(
        AuditFilterDTO $filters,
        ?int $forcedTenantUserId = null,
        ?string $forcedVisibility = null,
    ): Builder {
        /** @var Builder<AuditLog> $query */
        $query = AuditLog::query();

        // Enforce tenant boundary or filter by tenant_user_id
        if ($forcedTenantUserId !== null) {
            $query->where('tenant_user_id', $forcedTenantUserId);
        } elseif ($filters->tenantUserId !== null) {
            $query->where('tenant_user_id', $filters->tenantUserId);
        }

        // Enforce visibility constraint
        if ($forcedVisibility !== null) {
            $query->where('visibility', $forcedVisibility);
        }

        // Actor ID filter
        if ($filters->actorId !== null) {
            $query->where('actor_id', $filters->actorId);
        }

        // Category filter (first segment of dotted action)
        if ($filters->category !== null && $filters->category !== '') {
            $cat = $filters->category;
            $query->where(function (Builder $q) use ($cat): void {
                /** @psalm-suppress InvalidArgument */
                $q->where('action', 'like', $cat.'.%')
                    ->orWhere('action', '=', $cat);
            });
        }

        // Action filter
        if ($filters->action !== null && $filters->action !== '') {
            /** @psalm-suppress InvalidArgument */
            $query->where('action', '=', $filters->action);
        }

        // Outcome filter
        if ($filters->outcome !== null && $filters->outcome !== '') {
            $query->where('outcome', $filters->outcome);
        }

        // Subject type filter
        if ($filters->subjectType !== null && $filters->subjectType !== '') {
            $query->where('subject_type', $filters->subjectType);
        }

        // Subject ID filter
        if ($filters->subjectId !== null && $filters->subjectId !== '') {
            $query->where('subject_id', $filters->subjectId);
        }

        // Request ID filter
        if ($filters->requestId !== null && $filters->requestId !== '') {
            $query->where('request_id', $filters->requestId);
        }

        // IP filter (IPv4 and IPv6 converted via inet_pton)
        if ($filters->ip !== null && $filters->ip !== '') {
            $pton = @inet_pton($filters->ip);
            if ($pton !== false) {
                $query->where('ip', $pton);
            } else {
                $query->whereRaw('0 = 1');
            }
        }

        // Date range filters (occurred_at)
        if ($filters->from !== null && $filters->from !== '') {
            $query->where('occurred_at', '>=', Carbon::parse($filters->from));
        }

        if ($filters->to !== null && $filters->to !== '') {
            $query->where('occurred_at', '<=', Carbon::parse($filters->to));
        }

        return $query;
    }

    /**
     * Paginate query using keyset cursor on (occurred_at DESC, id DESC).
     * Strictly avoids OFFSET to ensure cursor stability and performance.
     *
     * @param  Builder<AuditLog>  $query
     * @return array{items: Collection<int, AuditLog>, next_cursor: string|null, per_page: int, total: int}
     */
    public function paginate(Builder $query, int $perPage, ?string $cursor): array
    {
        $perPage = max(1, min(100, $perPage));

        // Calculate total matching entries for pagination metadata before applying the keyset cursor
        $total = (clone $query)->count();

        // Decode and apply opaque cursor if present
        if ($cursor !== null && $cursor !== '') {
            $decodedJson = base64_decode($cursor, true);
            if ($decodedJson !== false) {
                /** @var array<string, mixed>|null $decoded */
                $decoded = json_decode($decodedJson, true);
                if (is_array($decoded)) {
                    $cursorTime = $decoded['occurred_at'] ?? $decoded['o'] ?? null;
                    $cursorId = $decoded['id'] ?? $decoded['i'] ?? null;

                    if ($cursorTime !== null && $cursorId !== null) {
                        $cursorTimeStr = Carbon::parse(Cast::string($cursorTime))->format('Y-m-d H:i:s.u');
                        $cursorIdInt = Cast::int($cursorId);

                        $query->where(function (Builder $q) use ($cursorTimeStr, $cursorIdInt): void {
                            $q->where('occurred_at', '<', $cursorTimeStr)
                                ->orWhere(function (Builder $sub) use ($cursorTimeStr, $cursorIdInt): void {
                                    $sub->where('occurred_at', '=', $cursorTimeStr)
                                        ->where('id', '<', $cursorIdInt);
                                });
                        });
                    }
                }
            }
        }

        // Order deterministically by occurred_at DESC, id DESC
        $query->orderByDesc('occurred_at')->orderByDesc('id');

        // Fetch perPage + 1 rows to check for next page
        $query->limit($perPage + 1);

        /** @var Collection<int, AuditLog> $results */
        $results = $query->get();

        if ($results->count() > $perPage) {
            $items = $results->take($perPage);
            /** @var AuditLog $lastItem */
            $lastItem = $items->last();

            $cursorOccurredAt = $lastItem->occurred_at->format('Y-m-d H:i:s.u');

            $nextCursor = base64_encode((string) json_encode([
                'occurred_at' => $cursorOccurredAt,
                'id' => $lastItem->id,
            ]));
        } else {
            $items = $results;
            $nextCursor = null;
        }

        return [
            'items' => $items,
            'next_cursor' => $nextCursor,
            'per_page' => $perPage,
            'total' => $total,
        ];
    }
}
