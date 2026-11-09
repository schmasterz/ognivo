<?php

declare(strict_types=1);

namespace App\Services\Network;

/**
 * Helper class for IPv4 CIDR calculations, range boundaries, and overlap detection.
 */
final class CidrHelper
{
    /**
     * Parse CIDR into [ip, prefix] or return null if invalid.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function parse(string $cidr): ?array
    {
        $parts = explode('/', trim($cidr));
        if (count($parts) !== 2 || ! ctype_digit($parts[1])) {
            return null;
        }

        $ip = $parts[0];
        $prefix = (int) $parts[1];

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        if ($prefix < 0 || $prefix > 32) {
            return null;
        }

        return [$ip, $prefix];
    }

    /**
     * Get unsigned network and broadcast integer boundaries for a CIDR.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function subnetRange(string $cidr): ?array
    {
        $parsed = self::parse($cidr);
        if ($parsed === null) {
            return null;
        }

        [$ip, $prefix] = $parsed;
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return null;
        }

        $ipU = $ipLong & 0xFFFFFFFF;

        if ($prefix === 32) {
            return [$ipU, $ipU];
        }

        if ($prefix === 0) {
            return [0, 0xFFFFFFFF];
        }

        $mask = ((-1 << (32 - $prefix)) & 0xFFFFFFFF);
        $network = $ipU & $mask;
        $broadcast = $network | (~$mask & 0xFFFFFFFF);

        return [$network, $broadcast];
    }

    /**
     * Calculate default first_usable and last_usable for an IPv4 CIDR.
     * - /32: first = last = address
     * - /31: first = network, last = broadcast (both usable per RFC 3021)
     * - /30 and larger: first = network + 1, last = broadcast - 1
     *
     * @return array{first: string, last: string}|null
     */
    public static function calculateUsableRange(string $cidr): ?array
    {
        $parsed = self::parse($cidr);
        if ($parsed === null) {
            return null;
        }

        [$ip, $prefix] = $parsed;
        $range = self::subnetRange($cidr);
        if ($range === null) {
            return null;
        }

        [$network, $broadcast] = $range;

        if ($prefix === 32) {
            $first = long2ip($network);
            $last = long2ip($network);
        } elseif ($prefix === 31) {
            $first = long2ip($network);
            $last = long2ip($broadcast);
        } else {
            $first = long2ip($network + 1);
            $last = long2ip($broadcast - 1);
        }

        return ['first' => $first, 'last' => $last];
    }

    /**
     * Convert an IPv4 address string to an unsigned integer (0 to 4294967295), or null if invalid.
     */
    public static function ipToUnsigned(string $ip): ?int
    {
        $long = ip2long($ip);
        if ($long === false) {
            return null;
        }

        return $long & 0xFFFFFFFF;
    }

    /**
     * Check if an IPv4 address falls within unsigned integer boundaries [network, broadcast].
     */
    public static function isIpInRange(string $ip, int $network, int $broadcast): bool
    {
        $u = self::ipToUnsigned($ip);
        if ($u === null) {
            return false;
        }

        return $u >= $network && $u <= $broadcast;
    }

    /**
     * Check if two CIDR blocks overlap.
     */
    public static function overlaps(string $cidrA, string $cidrB): bool
    {
        $rangeA = self::subnetRange($cidrA);
        $rangeB = self::subnetRange($cidrB);
        if ($rangeA === null || $rangeB === null) {
            return false;
        }

        return max($rangeA[0], $rangeB[0]) <= min($rangeA[1], $rangeB[1]);
    }
}
