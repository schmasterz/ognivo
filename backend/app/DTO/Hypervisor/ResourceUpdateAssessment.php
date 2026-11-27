<?php

declare(strict_types=1);

namespace App\DTO\Hypervisor;

/**
 * Aggregated per-resource capability assessment for a proposed instance resource update.
 */
final readonly class ResourceUpdateAssessment
{
    /**
     * @param  array<string, ResourceUpdateVerdict>  $verdicts
     * @param  array<string, string>  $reasons
     */
    public function __construct(
        public array $verdicts,
        public array $reasons = [],
    ) {}

    /**
     * Determine whether any resource update requires the instance to be stopped first.
     */
    public function requiresStop(): bool
    {
        return in_array(ResourceUpdateVerdict::RequiresStop, $this->verdicts, true);
    }

    /**
     * Determine whether all requested resource updates are supported and can be applied.
     */
    public function isApplicable(): bool
    {
        return ! in_array(ResourceUpdateVerdict::Unsupported, $this->verdicts, true);
    }
}
