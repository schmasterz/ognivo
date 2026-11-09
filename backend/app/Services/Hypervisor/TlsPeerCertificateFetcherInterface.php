<?php

declare(strict_types=1);

namespace App\Services\Hypervisor;

/**
 * Contract for inspecting and retrieving remote TLS peer certificates.
 */
interface TlsPeerCertificateFetcherInterface
{
    /**
     * Connect to remote TLS host and retrieve its presented X.509 server certificate.
     *
     * @return array{pem: string, fingerprint: string}
     */
    public function fetchPeerCertificate(string $host, int $port = 8443, int $timeout = 5): array;
}
