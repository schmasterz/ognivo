<?php

declare(strict_types=1);

namespace App\Events\Instance;

use App\Events\AbstractOgnivoEvent;
use Override;

/**
 * Domain event emitted when a virtual machine instance specification is resized.
 */
final class InstanceResized extends AbstractOgnivoEvent
{
    /**
     * @param  array{cpu?: int, memory_mb?: int, disk_gb?: int, net_download_mbps?: int|null, net_upload_mbps?: int|null}  $oldSpecs
     * @param  array{cpu?: int, memory_mb?: int, disk_gb?: int, net_download_mbps?: int|null, net_upload_mbps?: int|null}  $newSpecs
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        public readonly int $instanceId,
        public readonly array $oldSpecs,
        public readonly array $newSpecs,
        ?array $actor = null,
        public readonly ?string $operationId = null,
        public readonly ?int $fromPlanVersionId = null,
        public readonly ?int $toPlanVersionId = null,
    ) {
        parent::__construct(actor: $actor);
    }

    #[Override]
    public function getEventName(): string
    {
        return 'instance.resized';
    }

    /**
     * @return array{
     *     instance_id: int,
     *     old_specs: array{cpu?: int, memory_mb?: int, disk_gb?: int, net_download_mbps?: int|null, net_upload_mbps?: int|null},
     *     new_specs: array{cpu?: int, memory_mb?: int, disk_gb?: int, net_download_mbps?: int|null, net_upload_mbps?: int|null},
     *     operation_id?: string,
     *     from_plan_version_id?: int,
     *     to_plan_version_id?: int,
     * }
     */
    #[Override]
    public function getPayload(): array
    {
        $payload = [
            'instance_id' => $this->instanceId,
            'old_specs' => $this->oldSpecs,
            'new_specs' => $this->newSpecs,
        ];

        if ($this->operationId !== null) {
            $payload['operation_id'] = $this->operationId;
        }

        if ($this->fromPlanVersionId !== null) {
            $payload['from_plan_version_id'] = $this->fromPlanVersionId;
        }

        if ($this->toPlanVersionId !== null) {
            $payload['to_plan_version_id'] = $this->toPlanVersionId;
        }

        return $payload;
    }
}
