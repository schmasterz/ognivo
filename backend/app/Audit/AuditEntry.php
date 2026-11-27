<?php

declare(strict_types=1);

namespace App\Audit;

use App\Support\Cast;
use Illuminate\Database\Eloquent\Model;

final readonly class AuditEntry
{
    public AuditAction|string $action;

    public AuditOutcome|string $outcome;

    public mixed $subject;

    public ?int $tenantUserId;

    public string $visibility;

    /**
     * @var array<string, mixed> Non-personal context data
     */
    public array $context;

    /**
     * @var array<string, mixed> Personal data (PII)
     */
    public array $pii;

    public ?string $actorType;

    public ?int $actorId;

    public ?string $actorLabel;

    public ?int $impersonatorId;

    public ?int $operationId;

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $pii
     */
    public function __construct(
        AuditAction|string $action,
        AuditOutcome|string $outcome,
        mixed $subject = null,
        ?int $tenantUserId = null,
        string $visibility = 'admin',
        array $context = [],
        array $pii = [],
        ?string $actorType = null,
        ?int $actorId = null,
        ?string $actorLabel = null,
        ?int $impersonatorId = null,
        ?int $operationId = null,
    ) {
        $this->action = $action;
        $this->outcome = $outcome;
        $this->subject = $subject;
        $this->tenantUserId = $tenantUserId;
        $this->visibility = in_array($visibility, ['tenant', 'admin'], true) ? $visibility : 'admin';
        $this->context = $context;
        $this->pii = $pii;
        $this->actorType = $actorType;
        $this->actorId = $actorId;
        $this->actorLabel = $actorLabel;
        $this->impersonatorId = $impersonatorId;
        $this->operationId = $operationId;
    }

    public function getActionValue(): string
    {
        return $this->action instanceof AuditAction ? $this->action->value : Cast::string($this->action);
    }

    public function getOutcomeValue(): string
    {
        return $this->outcome instanceof AuditOutcome ? $this->outcome->value : Cast::string($this->outcome);
    }

    public function getSubjectType(): ?string
    {
        if ($this->subject instanceof Model) {
            return $this->subject->getMorphClass();
        }

        if (is_array($this->subject) && isset($this->subject['type'])) {
            return Cast::stringOrNull($this->subject['type']);
        }

        return null;
    }

    public function getSubjectId(): ?string
    {
        if ($this->subject instanceof Model) {
            $key = $this->subject->getKey();

            return $key !== null ? Cast::string($key) : null;
        }

        if (is_array($this->subject) && isset($this->subject['id'])) {
            return Cast::stringOrNull($this->subject['id']);
        }

        return null;
    }

    public function getSubjectLabel(): ?string
    {
        if ($this->subject instanceof Model) {
            $label = $this->subject->name
                ?? $this->subject->title
                ?? $this->subject->label
                ?? $this->subject->email
                ?? null;

            return Cast::stringOrNull($label);
        }

        if (is_array($this->subject) && isset($this->subject['label'])) {
            return Cast::stringOrNull($this->subject['label']);
        }

        return null;
    }
}
