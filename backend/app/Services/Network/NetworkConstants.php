<?php

declare(strict_types=1);

namespace App\Services\Network;

/**
 * Shared networking constants for IPAM and guest network configuration.
 */
final class NetworkConstants
{
    public const string ROUTED_GUEST_GATEWAY = '169.254.0.1';

    public const string LINK_LOCAL_CIDR = '169.254.0.0/16';
}
