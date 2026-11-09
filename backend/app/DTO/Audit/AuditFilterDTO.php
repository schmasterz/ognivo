<?php

declare(strict_types=1);

namespace App\DTO\Audit;

use App\DTO\Cast;
use Illuminate\Support\Str;

/**
 * Immutable Data Transfer Object for filtering and paginating audit logs.
 */
final readonly class AuditFilterDTO
{
    public ?int $actorId;

    public ?int $tenantUserId;

    public ?string $category;

    public ?string $action;

    public ?string $outcome;

    public ?string $subjectType;

    public ?string $subjectId;

    public ?string $requestId;

    public ?string $ip;

    public ?string $ipBinary;

    public ?string $from;

    public ?string $to;

    public int $perPage;

    public ?string $cursor;

    public function __construct(
        ?int $actorId = null,
        ?int $tenantUserId = null,
        ?string $category = null,
        ?string $action = null,
        ?string $outcome = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $requestId = null,
        ?string $ip = null,
        ?string $from = null,
        ?string $to = null,
        int $perPage = 25,
        ?string $cursor = null,
        ?string $ipBinary = null,
        // Optional snake_case parameter aliases for named argument construction
        ?int $actor_id = null,
        ?int $tenant_user_id = null,
        ?string $subject_type = null,
        ?string $subject_id = null,
        ?string $request_id = null,
        ?int $per_page = null,
    ) {
        $this->actorId = $actorId ?? $actor_id;
        $this->tenantUserId = $tenantUserId ?? $tenant_user_id;
        $this->category = $category;
        $this->action = $action;
        $this->outcome = $outcome;
        $this->subjectType = $subjectType ?? $subject_type;
        $this->subjectId = $subjectId ?? $subject_id;
        $this->requestId = $requestId ?? $request_id;
        $this->ip = $ip;
        $this->from = $from;
        $this->to = $to;

        $targetPerPage = $per_page !== null ? $per_page : $perPage;
        $this->perPage = max(1, min(100, $targetPerPage));
        $this->cursor = $cursor;

        if ($ipBinary !== null) {
            $this->ipBinary = $ipBinary;
        } elseif ($this->ip !== null && $this->ip !== '') {
            $pton = @inet_pton($this->ip);
            $this->ipBinary = $pton !== false ? $pton : null;
        } else {
            $this->ipBinary = null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $perPageRaw = $data['per_page'] ?? $data['perPage'] ?? 25;
        $perPage = max(1, min(100, Cast::int($perPageRaw, 25)));

        return new self(
            actorId: Cast::intOrNull($data['actor_id'] ?? $data['actorId'] ?? null),
            tenantUserId: Cast::intOrNull($data['tenant_user_id'] ?? $data['tenantUserId'] ?? null),
            category: Cast::stringOrNull($data['category'] ?? null),
            action: Cast::stringOrNull($data['action'] ?? null),
            outcome: Cast::stringOrNull($data['outcome'] ?? null),
            subjectType: Cast::stringOrNull($data['subject_type'] ?? $data['subjectType'] ?? null),
            subjectId: Cast::stringOrNull($data['subject_id'] ?? $data['subjectId'] ?? null),
            requestId: Cast::stringOrNull($data['request_id'] ?? $data['requestId'] ?? null),
            ip: Cast::stringOrNull($data['ip'] ?? null),
            from: Cast::stringOrNull($data['from'] ?? null),
            to: Cast::stringOrNull($data['to'] ?? null),
            perPage: $perPage,
            cursor: Cast::stringOrNull($data['cursor'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'actor_id' => $this->actorId,
            'tenant_user_id' => $this->tenantUserId,
            'category' => $this->category,
            'action' => $this->action,
            'outcome' => $this->outcome,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'request_id' => $this->requestId,
            'ip' => $this->ip,
            'from' => $this->from,
            'to' => $this->to,
            'per_page' => $this->perPage,
            'cursor' => $this->cursor,
        ], fn ($v) => $v !== null);
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'actor_id' => $this->actorId,
            'tenant_user_id' => $this->tenantUserId,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'request_id' => $this->requestId,
            'per_page' => $this->perPage,
            'ip_binary' => $this->ipBinary,
            default => $this->{Str::camel($name)} ?? null,
        };
    }
}
