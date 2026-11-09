<?php

declare(strict_types=1);

namespace App\Services\Hypervisor;

/**
 * Operating mode for HypervisorGateway HTTP client creation.
 *
 * Operational: Normal runtime operations (VM lifecycle, metrics, provisioning).
 *              Requires a cryptographically sound public key pin for every non-fake host.
 * Onboarding:  Trust / connect / verify discovery flows where server certificate
 *              is being inspected or discovered before pinning.
 */
enum GatewayMode: string
{
    case Operational = 'operational';
    case Onboarding = 'onboarding';
}
