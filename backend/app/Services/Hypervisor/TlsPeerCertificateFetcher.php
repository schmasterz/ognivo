<?php

declare(strict_types=1);

namespace App\Services\Hypervisor;

use OpenSSLCertificate;
use Override;
use RuntimeException;

/**
 * Service to inspect remote Incus TLS certificate during handshake before establishing trust.
 */
final class TlsPeerCertificateFetcher implements TlsPeerCertificateFetcherInterface
{
    /**
     * Connect to remote TLS host and retrieve its presented X.509 server certificate.
     *
     * @return array{pem: string, fingerprint: string}
     *
     * @throws RuntimeException If connection fails or certificate cannot be captured, or if target is private/loopback
     */
    #[Override]
    public function fetchPeerCertificate(string $host, int $port = 8443, int $timeout = 5): array
    {
        $raw = trim($host);
        // Strip any protocol if present
        $cleanHost = preg_replace('#^https?://#i', '', $raw);
        if ($cleanHost === null) {
            $cleanHost = $raw;
        }

        $isIpv6 = false;

        // Check for bracketed IPv6 literal, e.g. [2001:db8::1]:8443 or [::1]
        if (preg_match('/^\[([a-fA-F0-9:]+)\](?::(\d+))?(\/.*)?$/', $cleanHost, $matches)) {
            $cleanHost = $matches[1];
            if (! empty($matches[2])) {
                $port = (int) $matches[2];
            }
            $isIpv6 = true;
        } elseif (filter_var(explode('/', $cleanHost, 2)[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Unbracketed IPv6 without port, e.g. 2001:db8::1 or ::1
            $cleanHost = explode('/', $cleanHost, 2)[0];
            $isIpv6 = true;
        } else {
            // Could be IPv4 with port or domain with port, e.g. 192.168.1.1:8443 or host.internal:8443
            $withoutPath = explode('/', $cleanHost, 2)[0];
            if (str_contains($withoutPath, ':') && substr_count($withoutPath, ':') === 1) {
                $parts = explode(':', $withoutPath, 2);
                $cleanHost = $parts[0];
                $port = (int) ($parts[1] ?? $port);
            } else {
                $cleanHost = $withoutPath;
            }
        }

        $cleanHost = rtrim(explode('/', $cleanHost, 2)[0], '/');
        if (filter_var($cleanHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $isIpv6 = true;
        }

        // Reject private/loopback targets unless config('hypervisor.allow_private_endpoints') is true
        $allowPrivate = (bool) config('hypervisor.allow_private_endpoints', false);
        if (! $allowPrivate && $this->isPrivateOrLoopback($cleanHost)) {
            throw new RuntimeException(sprintf(
                'Connections to private or loopback endpoint [%s] are not allowed.',
                $cleanHost
            ));
        }

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $remoteSocket = $isIpv6
            ? sprintf('ssl://[%s]:%d', $cleanHost, $port)
            : sprintf('ssl://%s:%d', $cleanHost, $port);

        $errno = 0;
        $errstr = '';

        $client = @stream_socket_client(
            $remoteSocket,
            $errno,
            $errstr,
            (float) $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($client === false) {
            throw new RuntimeException(sprintf('Failed to connect to %s:%d: %s', $cleanHost, $port, $errstr ?: 'Connection refused'));
        }

        try {
            $params = stream_context_get_params($client);
            $options = $params['options'];
            $peerCert = isset($options['ssl']) && is_array($options['ssl'])
                ? ($options['ssl']['peer_certificate'] ?? null)
                : null;

            if (! $peerCert instanceof OpenSSLCertificate) {
                throw new RuntimeException('Failed to capture remote TLS peer certificate.');
            }

            $pem = '';
            openssl_x509_export($peerCert, $pem);
            /** @var string $pem */
            if ($pem === '') {
                throw new RuntimeException('Failed to export remote TLS peer certificate PEM.');
            }

            $fingerprint = strtolower((string) openssl_x509_fingerprint($pem, 'sha256'));

            return [
                'pem' => $pem,
                'fingerprint' => $fingerprint,
            ];
        } finally {
            fclose($client);
        }
    }

    /**
     * Check whether an endpoint hostname or IP represents a private or loopback address.
     */
    protected function isPrivateOrLoopback(string $host): bool
    {
        // 1. Literal IP check
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $isPublic = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

            return ! $isPublic;
        }

        // 2. Common loopback/internal hostnames
        $lower = strtolower($host);
        if ($lower === 'localhost' || str_ends_with($lower, '.localhost') || str_ends_with($lower, '.local') || str_ends_with($lower, '.internal')) {
            return true;
        }

        // 3. DNS resolution check (IPv4)
        $resolvedIpv4s = @gethostbynamel($host);
        if (is_array($resolvedIpv4s)) {
            foreach ($resolvedIpv4s as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    return true;
                }
            }
        }

        // 4. DNS resolution check (IPv6)
        $dnsAaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($dnsAaaa)) {
            foreach ($dnsAaaa as $record) {
                if (isset($record['ipv6']) && filter_var($record['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    return true;
                }
            }
        }

        return false;
    }
}
