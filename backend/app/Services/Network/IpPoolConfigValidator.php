<?php

declare(strict_types=1);

namespace App\Services\Network;

use App\Support\Network\NetworkConstants;
use InvalidArgumentException;

/**
 * Validates IP pool configuration across all supported network modes.
 */
final class IpPoolConfigValidator
{
    private const string LINK_LOCAL_ERROR = 'Link-local addresses (169.254.0.0/16) cannot be used as pool CIDR, gateway, or excluded IPs.';

    /**
     * Validate IP pool configuration for a given network mode.
     *
     * @param  array<array-key, mixed>  $excludedIps
     * @return array<string, list<string>>
     */
    public static function validate(
        string $networkMode,
        string $cidr,
        ?string $gateway = null,
        array $excludedIps = [],
        ?string $firstUsable = null,
        ?string $lastUsable = null,
    ): array {
        /** @var array<string, list<string>> $errors */
        $errors = [];

        self::validateLinkLocal($cidr, $gateway, $excludedIps, $errors);

        $parsed = CidrHelper::parse($cidr);
        if ($parsed === null) {
            self::addError($errors, 'cidr', 'CIDR must be in format IPv4/prefix (e.g. 192.168.1.0/24).');

            return $errors;
        }

        [, $prefix] = $parsed;

        if ($prefix < 16 || $prefix > 32) {
            self::addError($errors, 'cidr', 'CIDR prefix length must be between 16 and 32.');

            return $errors;
        }

        if (in_array($networkMode, ['bridged', 'macvlan'], true) && $prefix > 30) {
            self::addError($errors, 'cidr', 'Bridged and macvlan modes only support /16 to /30 subnets.');
        }

        $range = CidrHelper::subnetRange($cidr);
        if ($range === null) {
            self::addError($errors, 'cidr', 'Invalid CIDR subnet range.');

            return $errors;
        }

        [$network, $broadcast] = $range;

        if ($prefix === 32) {
            $minAllowed = $network;
            $maxAllowed = $network;
        } elseif ($prefix === 31) {
            $minAllowed = $network;
            $maxAllowed = $broadcast;
        } else {
            $minAllowed = $network + 1;
            $maxAllowed = $broadcast - 1;
        }

        self::validateUsableRange($firstUsable, $lastUsable, $minAllowed, $maxAllowed, $errors);
        self::validateGateway($networkMode, $prefix, $gateway, $network, $broadcast, $errors);

        return $errors;
    }

    /**
     * Reject pool CIDR, gateway, and excluded IPs within the link-local range (169.254.0.0/16).
     *
     * @param  array<array-key, mixed>  $excludedIps
     * @param  array<string, list<string>>  $errors
     */
    private static function validateLinkLocal(
        string $cidr,
        ?string $gateway,
        array $excludedIps,
        array &$errors,
    ): void {
        if (CidrHelper::overlaps($cidr, NetworkConstants::LINK_LOCAL_CIDR)) {
            self::addError($errors, 'cidr', self::LINK_LOCAL_ERROR);
        }

        if ($gateway !== null) {
            $trimmedGateway = trim($gateway);
            if ($trimmedGateway !== '' && self::isLinkLocalIp($trimmedGateway)) {
                self::addError($errors, 'gateway', self::LINK_LOCAL_ERROR);
            }
        }

        foreach ($excludedIps as $excludedIp) {
            if (is_string($excludedIp) && self::isLinkLocalIp(trim($excludedIp))) {
                self::addError($errors, 'excluded_ips', self::LINK_LOCAL_ERROR);
                break;
            }
        }
    }

    /**
     * Check whether an IPv4 address falls inside 169.254.0.0/16.
     */
    private static function isLinkLocalIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $range = CidrHelper::subnetRange(NetworkConstants::LINK_LOCAL_CIDR);
        if ($range === null) {
            return false;
        }

        return CidrHelper::isIpInRange($ip, $range[0], $range[1]);
    }

    /**
     * Validate optional first_usable and last_usable bounds against the subnet usable range.
     *
     * @param  array<string, list<string>>  $errors
     */
    private static function validateUsableRange(
        ?string $firstUsable,
        ?string $lastUsable,
        int $minAllowed,
        int $maxAllowed,
        array &$errors,
    ): void {
        $resolved = [];

        foreach ([
            'first_usable' => ['value' => $firstUsable, 'label' => 'First usable IP'],
            'last_usable' => ['value' => $lastUsable, 'label' => 'Last usable IP'],
        ] as $field => $spec) {
            $trimmed = $spec['value'] !== null ? trim($spec['value']) : '';
            if ($trimmed === '') {
                $resolved[$field] = null;

                continue;
            }

            if (filter_var($trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                self::addError($errors, $field, "{$spec['label']} must be a valid IPv4 address.");
                $resolved[$field] = null;

                continue;
            }

            $ipLong = CidrHelper::ipToUnsigned($trimmed);
            if ($ipLong === null || $ipLong < $minAllowed || $ipLong > $maxAllowed) {
                self::addError($errors, $field, "{$spec['label']} is outside the CIDR subnet.");
            }

            $resolved[$field] = $ipLong;
        }

        if (
            isset($resolved['first_usable'], $resolved['last_usable'])
            && $resolved['first_usable'] > $resolved['last_usable']
        ) {
            self::addError($errors, 'first_usable', 'First usable IP cannot be greater than last usable IP.');
        }
    }

    /**
     * Validate gateway requirement and bounds according to the network mode.
     *
     * @param  array<string, list<string>>  $errors
     */
    private static function validateGateway(
        string $networkMode,
        int $prefix,
        ?string $gateway,
        int $network,
        int $broadcast,
        array &$errors,
    ): void {
        $trimmedGateway = $gateway !== null ? trim($gateway) : '';
        $hasGateway = $trimmedGateway !== '';

        if (in_array($networkMode, ['bridged', 'macvlan'], true)) {
            if (! $hasGateway) {
                self::addError($errors, 'gateway', 'Gateway is required for bridged and macvlan pool modes.');

                return;
            }

            self::validateGatewayFormatAndRange($trimmedGateway, $prefix, $network, $broadcast, $errors);

            return;
        }

        if ($networkMode === 'manual') {
            if ($prefix <= 30 && ! $hasGateway) {
                self::addError($errors, 'gateway', 'Gateway is required for subnets /16 to /30.');

                return;
            }

            if ($hasGateway) {
                self::validateGatewayFormatAndRange($trimmedGateway, $prefix, $network, $broadcast, $errors);
            }

            return;
        }

        if ($networkMode === 'routed') {
            if ($hasGateway) {
                self::validateGatewayFormatAndRange($trimmedGateway, $prefix, $network, $broadcast, $errors);
            }

            return;
        }

        throw new InvalidArgumentException("Unsupported network mode: {$networkMode}");
    }

    /**
     * Validate that a provided gateway is IPv4, inside the CIDR range, and not network/broadcast for /16-/30.
     *
     * @param  array<string, list<string>>  $errors
     */
    private static function validateGatewayFormatAndRange(
        string $gateway,
        int $prefix,
        int $network,
        int $broadcast,
        array &$errors,
    ): void {
        if (filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            self::addError($errors, 'gateway', 'Gateway must be a valid IPv4 address.');

            return;
        }

        $gwLong = CidrHelper::ipToUnsigned($gateway);
        if ($gwLong === null || $gwLong < $network || $gwLong > $broadcast) {
            self::addError($errors, 'gateway', 'Gateway IP is outside the CIDR subnet.');

            return;
        }

        if ($prefix <= 30 && ($gwLong === $network || $gwLong === $broadcast)) {
            self::addError($errors, 'gateway', 'Gateway IP cannot be the network or broadcast address.');
        }
    }

    /**
     * Append a validation error message under the given field key.
     *
     * @param  array<string, list<string>>  $errors
     */
    private static function addError(array &$errors, string $field, string $message): void
    {
        $errors[$field] ??= [];
        $errors[$field][] = $message;
    }
}
