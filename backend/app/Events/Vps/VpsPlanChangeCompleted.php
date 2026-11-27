<?php

declare(strict_types=1);

namespace App\Events\Vps;

use App\Events\AbstractOgnivoEvent;
use Override;

/**
 * Domain event emitted when a VPS plan change completes and VM resources are committed.
 *
 * @experimental This event is in Developer Preview and may evolve in minor 1.x releases based on feedback from real reference implementations. It will be frozen under full SemVer once proven with core-integrated modules.
 */
final class VpsPlanChangeCompleted extends AbstractOgnivoEvent
{
    /**
     * @param  array<string, mixed>  $oldResources
     * @param  array<string, mixed>  $newResources
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        public readonly int $planChangeId,
        public readonly string $publicId,
        public readonly int $virtualMachineId,
        public readonly int $userId,
        public readonly ?int $fromVersionId,
        public readonly int $toVersionId,
        public readonly array $oldResources,
        public readonly array $newResources,
        ?array $actor = null,
    ) {
        parent::__construct(actor: $actor);
    }

    #[Override]
    public function getEventName(): string
    {
        return 'vps.plan_change.completed';
    }

    /**
     * @return array{
     *     vps_plan_change_id: int,
     *     public_id: string,
     *     virtual_machine_id: int,
     *     user_id: int,
     *     from_version_id: int|null,
     *     to_version_id: int,
     *     old_resources: array<string, mixed>,
     *     new_resources: array<string, mixed>,
     * }
     */
    #[Override]
    public function getPayload(): array
    {
        return [
            'vps_plan_change_id' => $this->planChangeId,
            'public_id' => $this->publicId,
            'virtual_machine_id' => $this->virtualMachineId,
            'user_id' => $this->userId,
            'from_version_id' => $this->fromVersionId,
            'to_version_id' => $this->toVersionId,
            'old_resources' => $this->oldResources,
            'new_resources' => $this->newResources,
        ];
    }
}
