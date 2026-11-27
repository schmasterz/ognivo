<?php

declare(strict_types=1);

namespace App\Services\Hypervisor;

use InvalidArgumentException;

/**
 * Builds Incus/LXD `eth0` NIC device specifications with optional ingress/egress bandwidth limits.
 */
final class NicDeviceBuilder
{
    /**
     * Incus NIC bandwidth limit keys mapped by traffic direction.
     *
     * @var array{download: string, upload: string}
     */
    public const array LIMIT_KEYS = [
        'download' => 'limits.ingress',
        'upload' => 'limits.egress',
    ];

    /**
     * Build the `eth0` NIC device specification for `routed`, `bridged`, or `macvlan` mode.
     *
     * @return array<string, string>
     */
    public function build(
        string $networkMode,
        string $parentInterface,
        ?string $ipAddress = null,
        ?string $macAddress = null,
        ?int $downloadMbps = null,
        ?int $uploadMbps = null,
    ): array {
        if (! in_array($networkMode, ['routed', 'bridged', 'macvlan'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported NIC network mode "%s".', $networkMode));
        }

        $nic = [
            'type' => 'nic',
            'nictype' => $networkMode,
            'parent' => $parentInterface,
        ];

        if ($networkMode === 'routed' && $ipAddress !== null && $ipAddress !== '') {
            $nic['ipv4.address'] = $ipAddress;
        }

        if ($macAddress !== null && $macAddress !== '') {
            $nic['hwaddr'] = $macAddress;
        }

        if ($downloadMbps !== null) {
            $nic[self::LIMIT_KEYS['download']] = sprintf('%dMbit', $downloadMbps);
        }

        if ($uploadMbps !== null) {
            $nic[self::LIMIT_KEYS['upload']] = sprintf('%dMbit', $uploadMbps);
        }

        return $nic;
    }
}
