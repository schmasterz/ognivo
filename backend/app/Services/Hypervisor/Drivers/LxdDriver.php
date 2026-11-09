<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\Drivers;

use App\Models\Host;
use App\Services\Hypervisor\HypervisorGateway;
use App\Services\Hypervisor\InstanceSpecBuilder;
use App\Services\Hypervisor\TenantProjectProvisioner;
use App\Services\PanelCertificateService;
use Override;

/**
 * Native LXD REST API hypervisor driver communicating over HTTPS with mTLS.
 */
final class LxdDriver extends AbstractIncusLxdDriver
{
    public function __construct(
        ?Host $host = null,
        ?PanelCertificateService $certService = null,
        ?HypervisorGateway $gateway = null,
        ?TenantProjectProvisioner $projectProvisioner = null,
        ?InstanceSpecBuilder $instanceSpecBuilder = null,
    ) {
        parent::__construct($host, $certService, $gateway, $projectProvisioner, $instanceSpecBuilder);
    }

    #[Override]
    public function getDriverName(): string
    {
        return 'lxd';
    }
}
