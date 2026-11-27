<?php

declare(strict_types=1);

namespace App\Events\Vps;

use App\Events\AbstractOgnivoEvent;
use Override;

/**
 * Domain event emitted when a VPS plan is published to the catalog.
 *
 * @experimental This event is in Developer Preview and may evolve in minor 1.x releases based on feedback from real reference implementations. It will be frozen under full SemVer once proven with core-integrated modules.
 */
final class VpsPlanPublished extends AbstractOgnivoEvent
{
    /**
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        public readonly int $planId,
        public readonly string $slug,
        public readonly string $name,
        public readonly int $currentVersionId,
        ?array $actor = null,
    ) {
        parent::__construct(actor: $actor);
    }

    #[Override]
    public function getEventName(): string
    {
        return 'vps.plan.published';
    }

    /**
     * @return array{
     *     vps_plan_id: int,
     *     slug: string,
     *     name: string,
     *     current_version_id: int,
     * }
     */
    #[Override]
    public function getPayload(): array
    {
        return [
            'vps_plan_id' => $this->planId,
            'slug' => $this->slug,
            'name' => $this->name,
            'current_version_id' => $this->currentVersionId,
        ];
    }
}
