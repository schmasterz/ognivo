<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\UserQuota;
use App\Services\Hypervisor\TenantProjectProvisioner;

/**
 * Event listener triggered when a UserQuota is saved, re-synchronizing project limits across hypervisors.
 */
class SyncUserQuotaToHypervisorProjectListener
{
    public function __construct(
        protected readonly TenantProjectProvisioner $provisioner,
    ) {}

    /**
     * Handle the UserQuota saved event.
     */
    public function handle(UserQuota $quota): void
    {
        $this->provisioner->syncUserQuotaAcrossHosts($quota);
    }
}
