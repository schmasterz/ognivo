<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Contract for pluggable billing / resource usage metering providers.
 */
interface BillingProviderInterface
{
    /**
     * Unique identifier for this billing provider (e.g. "stripe", "custom").
     */
    public function getIdentifier(): string;

    /**
     * Record or emit a resource usage measurement for a user/tenant.
     *
     * @param  int  $userId  Tenant or user ID.
     * @param  string  $metric  Usage metric name (e.g. "vm.uptime_hours", "bandwidth.egress_gb").
     * @param  float  $quantity  Quantity consumed.
     * @param  array<string, mixed>  $tags  Additional dimensions (e.g. ["vm_id" => 42]).
     */
    public function recordUsage(int $userId, string $metric, float $quantity, array $tags = []): void;
}
