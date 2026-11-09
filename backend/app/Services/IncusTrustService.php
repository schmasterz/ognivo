<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\DTO\Incus\IncusConnectionResult;
use App\Models\Host;
use App\Services\Hypervisor\GatewayMode;
use App\Services\Hypervisor\HypervisorGateway;
use App\Services\Hypervisor\TlsPeerCertificateFetcher;
use App\Services\Hypervisor\TlsPeerCertificateFetcherInterface;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Service managing Incus host onboarding via single-use Trust Tokens or manual certificate pinning.
 */
final class IncusTrustService
{
    public function __construct(
        protected readonly PanelCertificateService $certService,
        protected readonly IncusConnectionChecker $connectionChecker,
        protected readonly TlsPeerCertificateFetcherInterface $tlsPeerFetcher = new TlsPeerCertificateFetcher,
        protected readonly ?HypervisorGateway $gateway = null,
        protected ?AuditLogger $auditLogger = null,
    ) {
        $this->auditLogger ??= app(AuditLogger::class);
    }

    private function logger(): AuditLogger
    {
        return $this->auditLogger ??= app(AuditLogger::class);
    }

    /**
     * Add and pair a new Incus host using a single-use trust token.
     *
     * @param  array<string, mixed>  $extraAttributes
     *
     * @throws InvalidArgumentException On malformed or expired token
     * @throws RuntimeException On MITM fingerprint mismatch or network failure
     */
    public function addHostWithToken(string $name, string $token, array $extraAttributes = []): Host
    {
        $driver = isset($extraAttributes['driver']) && is_string($extraAttributes['driver']) ? $extraAttributes['driver'] : 'incus';

        $connectionDetails = $this->exchangeTrustToken($token, $driver);

        // Store host with pinned certificate and working address (NEVER store the token!)
        /** @var Host $host */
        $host = Host::create(array_merge([
            'name' => $name,
            'endpoint_url' => $connectionDetails['endpoint_url'],
            'server_certificate' => $connectionDetails['server_certificate'],
            'server_fingerprint' => $connectionDetails['server_fingerprint'],
            'server_public_key_pin' => $connectionDetails['server_public_key_pin'],
            'driver' => $driver,
            'token' => null, // Explicitly null: single-use token is never stored!
            'status' => IncusConnectionResult::STATE_OK,
            'needs_retrust' => false,
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 2000,
        ], $extraAttributes));

        // Run connection check to confirm
        $this->connectionChecker->check($host);

        $this->logger()->record(new AuditEntry(
            action: AuditAction::HostRetrusted,
            outcome: AuditOutcome::Success,
            subject: $host,
            tenantUserId: null,
            visibility: 'admin',
            context: [
                'name' => $host->name,
                'endpoint_host' => parse_url((string) $host->endpoint_url, PHP_URL_HOST) ?: (string) $host->endpoint_url,
                'server_fingerprint' => $host->server_fingerprint,
            ],
        ));

        return $host;
    }

    /**
     * Re-trust an existing Host using a new trust token.
     *
     * @throws InvalidArgumentException On malformed or expired token
     * @throws RuntimeException On MITM fingerprint mismatch or network failure
     */
    public function retrustHostWithToken(Host $host, string $token): Host
    {
        if ($host->driver === 'fake') {
            $this->parseAndValidateToken($token);

            $host->update([
                'status' => IncusConnectionResult::STATE_OK,
                'needs_retrust' => false,
            ]);

            $this->connectionChecker->check($host);

            $this->logger()->record(new AuditEntry(
                action: AuditAction::HostRetrusted,
                outcome: AuditOutcome::Success,
                subject: $host,
                tenantUserId: null,
                visibility: 'admin',
                context: [
                    'name' => $host->name,
                    'endpoint_host' => parse_url((string) $host->endpoint_url, PHP_URL_HOST) ?: (string) $host->endpoint_url,
                    'server_fingerprint' => $host->server_fingerprint,
                ],
            ));

            return $host;
        }

        $connectionDetails = $this->exchangeTrustToken($token, $host->driver);

        $host->update([
            'endpoint_url' => $connectionDetails['endpoint_url'],
            'server_certificate' => $connectionDetails['server_certificate'],
            'server_fingerprint' => $connectionDetails['server_fingerprint'],
            'server_public_key_pin' => $connectionDetails['server_public_key_pin'],
            'token' => null,
            'status' => IncusConnectionResult::STATE_OK,
            'needs_retrust' => false,
        ]);

        $this->connectionChecker->check($host);

        $this->logger()->record(new AuditEntry(
            action: AuditAction::HostRetrusted,
            outcome: AuditOutcome::Success,
            subject: $host,
            tenantUserId: null,
            visibility: 'admin',
            context: [
                'name' => $host->name,
                'endpoint_host' => parse_url((string) $host->endpoint_url, PHP_URL_HOST) ?: (string) $host->endpoint_url,
                'server_fingerprint' => $host->server_fingerprint,
            ],
        ));

        return $host;
    }

    /**
     * Connect to hypervisor using trust token and register the panel certificate.
     *
     * @return array{endpoint_url: string, server_certificate: string, server_fingerprint: string, server_public_key_pin: string}
     *
     * @throws InvalidArgumentException On malformed or expired token
     * @throws RuntimeException On MITM fingerprint mismatch or network failure
     */
    protected function exchangeTrustToken(string $token, string $driver = 'incus'): array
    {
        // 1. Base64-decode and parse JSON
        $tokenData = $this->parseAndValidateToken($token);

        // 2. Ensure panel certificate exists (auto-generate if missing)
        $panelCert = $this->certService->getActiveCertificate();
        if ($panelCert === null || $panelCert->isExpired()) {
            $panelCert = $this->certService->generate();
        }

        /** @var list<string> $addresses */
        $addresses = $tokenData['addresses'];
        $tokenFingerprint = strtolower((string) $tokenData['fingerprint']);
        $secret = (string) $tokenData['secret'];
        $clientName = (string) $tokenData['client_name'];

        // 3. Connect to first reachable address
        $workingAddress = null;
        $serverCertPem = null;
        $serverFingerprint = null;
        $lastError = null;

        foreach ($addresses as $address) {
            try {
                $peerInfo = $this->tlsPeerFetcher->fetchPeerCertificate($address);

                // 4. Man-in-the-middle check: compare peer fingerprint with token fingerprint
                if (strtolower($peerInfo['fingerprint']) !== $tokenFingerprint) {
                    throw new RuntimeException(sprintf(
                        'Security alert: Server certificate fingerprint (%s) does not match the trust token fingerprint (%s). Aborting.',
                        $peerInfo['fingerprint'],
                        $tokenFingerprint
                    ));
                }

                $workingAddress = $address;
                $serverCertPem = $peerInfo['pem'];
                $serverFingerprint = $peerInfo['fingerprint'];
                break;
            } catch (RuntimeException $e) {
                // If it was the MITM fingerprint mismatch, fail immediately and send nothing!
                if (str_contains($e->getMessage(), 'Security alert: Server certificate fingerprint')) {
                    throw $e;
                }
                $lastError = $e->getMessage();
            }
        }

        if ($workingAddress === null || $serverCertPem === null || $serverFingerprint === null) {
            $reason = $lastError !== null && $lastError !== '' ? $lastError : 'Connection refused';
            throw new RuntimeException(
                sprintf('Could not reach any of the addresses in the trust token: %s', $reason)
            );
        }

        // 5. Send only public panel certificate to POST /1.0/certificates with trust_token using HypervisorGateway
        $endpointUrl = sprintf('https://%s', $workingAddress);
        $serverPublicKeyPin = HypervisorGateway::computePinFromCertificate($serverCertPem);

        $candidateHost = new Host([
            'endpoint_url' => $endpointUrl,
            'server_certificate' => $serverCertPem,
            'server_fingerprint' => $serverFingerprint,
            'server_public_key_pin' => $serverPublicKeyPin,
            'driver' => $driver,
        ]);

        $gateway = $this->gateway ?? app(HypervisorGateway::class);
        $client = $gateway->client($candidateHost, $panelCert, mode: GatewayMode::Onboarding);

        $postPayload = [
            'type' => 'client',
            'name' => $clientName,
            'trust_token' => $token,
        ];

        /** @var Response $postResponse */
        $postResponse = $client->timeout(10)->post('/1.0/certificates', $postPayload);

        if (! $postResponse->successful()) {
            $isAlreadyTrusted = false;
            try {
                /** @var Response $checkResponse */
                $checkResponse = $client->timeout(5)->get('/1.0');

                if ($checkResponse->successful()) {
                    $checkData = $checkResponse->json();
                    /** @var array<string, mixed> $checkMetadata */
                    $checkMetadata = is_array($checkData) && isset($checkData['metadata']) && is_array($checkData['metadata'])
                        ? $checkData['metadata']
                        : [];
                    if (($checkMetadata['auth'] ?? '') === 'trusted') {
                        $isAlreadyTrusted = true;
                    }
                }
            } catch (Throwable) {
                $isAlreadyTrusted = false;
            }

            if (! $isAlreadyTrusted) {
                $errData = $postResponse->json();
                $msg = is_array($errData) && isset($errData['error']) && is_string($errData['error'])
                    ? $errData['error']
                    : sprintf('HTTP %d from certificates endpoint', $postResponse->status());

                throw new RuntimeException(sprintf('Hypervisor rejected trust token: %s', $msg));
            }
        }

        return [
            'endpoint_url' => $endpointUrl,
            'server_certificate' => $serverCertPem,
            'server_fingerprint' => $serverFingerprint,
            'server_public_key_pin' => $serverPublicKeyPin,
        ];
    }

    /**
     * Add a host using the manual certificate path (pre-trusted certificate).
     *
     * @param  array<string, mixed>  $extraAttributes
     */
    public function addHostWithCertificate(
        string $name,
        string $address,
        string $expectedFingerprint,
        array $extraAttributes = []
    ): Host {
        $expectedFp = strtolower(trim($expectedFingerprint));
        if ($expectedFp === '') {
            throw new InvalidArgumentException('Expected server fingerprint is required.');
        }

        $cleanAddress = preg_replace('#^https?://#', '', trim($address)) ?? trim($address);

        // Fetch peer certificate
        $peerInfo = $this->tlsPeerFetcher->fetchPeerCertificate($cleanAddress);

        // Check fingerprint
        if (strtolower($peerInfo['fingerprint']) !== $expectedFp) {
            throw new RuntimeException(sprintf(
                'Security alert: Server certificate fingerprint (%s) does not match expected fingerprint (%s). Aborting.',
                $peerInfo['fingerprint'],
                $expectedFp
            ));
        }

        $endpointUrl = sprintf('https://%s', $cleanAddress);
        $serverPublicKeyPin = HypervisorGateway::computePinFromCertificate($peerInfo['pem']);

        /** @var Host $host */
        $host = Host::create(array_merge([
            'name' => $name,
            'endpoint_url' => $endpointUrl,
            'server_certificate' => $peerInfo['pem'],
            'server_fingerprint' => $peerInfo['fingerprint'],
            'server_public_key_pin' => $serverPublicKeyPin,
            'driver' => 'incus',
            'token' => null,
            'status' => IncusConnectionResult::STATE_OK,
            'needs_retrust' => false,
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 2000,
        ], $extraAttributes));

        $this->connectionChecker->check($host);

        $this->logger()->record(new AuditEntry(
            action: AuditAction::HostRetrusted,
            outcome: AuditOutcome::Success,
            subject: $host,
            tenantUserId: null,
            visibility: 'admin',
            context: [
                'name' => $host->name,
                'endpoint_host' => parse_url((string) $host->endpoint_url, PHP_URL_HOST) ?: (string) $host->endpoint_url,
                'server_fingerprint' => $host->server_fingerprint,
            ],
        ));

        return $host;
    }

    /**
     * Parse and validate trust token JSON payload.
     *
     * @return array{client_name: string, fingerprint: string, addresses: list<string>, secret: string, expires_at: string}
     */
    public function parseAndValidateToken(string $token): array
    {
        $raw = trim($token);
        if ($raw === '') {
            throw new InvalidArgumentException('Trust token cannot be empty.');
        }

        $decoded = base64_decode($raw, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Malformed trust token: not valid Base64 string.');
        }

        /** @var mixed $json */
        $json = json_decode($decoded, true);
        if (! is_array($json)) {
            throw new InvalidArgumentException('Malformed trust token: decoded payload is not valid JSON.');
        }

        $requiredFields = ['client_name', 'fingerprint', 'addresses', 'secret', 'expires_at'];
        foreach ($requiredFields as $field) {
            if (! array_key_exists($field, $json)) {
                throw new InvalidArgumentException(sprintf('Malformed trust token: missing required field "%s".', $field));
            }
        }

        if (! is_string($json['client_name']) || trim($json['client_name']) === '') {
            throw new InvalidArgumentException('Malformed trust token: invalid "client_name" field.');
        }

        if (! is_string($json['fingerprint']) || trim($json['fingerprint']) === '') {
            throw new InvalidArgumentException('Malformed trust token: invalid "fingerprint" field.');
        }

        if (! is_array($json['addresses']) || empty($json['addresses'])) {
            throw new InvalidArgumentException('Malformed trust token: "addresses" must be a non-empty array.');
        }

        /** @var list<string> $addressList */
        $addressList = [];
        foreach ($json['addresses'] as $addr) {
            if (is_string($addr) && trim($addr) !== '') {
                $addressList[] = trim($addr);
            }
        }

        if (empty($addressList)) {
            throw new InvalidArgumentException('Malformed trust token: no valid addresses found.');
        }

        if (! is_string($json['secret']) || trim($json['secret']) === '') {
            throw new InvalidArgumentException('Malformed trust token: invalid "secret" field.');
        }

        if (! is_string($json['expires_at']) || trim($json['expires_at']) === '') {
            throw new InvalidArgumentException('Malformed trust token: invalid "expires_at" field.');
        }

        // Validate expiration timestamp
        try {
            $expiresAt = Carbon::parse($json['expires_at']);
            // Allow 0001-01-01 as non-expiring if Incus generates it, but if expired in past, reject
            if ($expiresAt->year > 1 && $expiresAt->isPast()) {
                throw new InvalidArgumentException(sprintf('Trust token expired on %s.', $expiresAt->toIso8601String()));
            }
        } catch (Throwable $e) {
            if ($e instanceof InvalidArgumentException) {
                throw $e;
            }
            throw new InvalidArgumentException('Malformed trust token: unparseable "expires_at" timestamp.');
        }

        return [
            'client_name' => $json['client_name'],
            'fingerprint' => $json['fingerprint'],
            'addresses' => $addressList,
            'secret' => $json['secret'],
            'expires_at' => $json['expires_at'],
        ];
    }
}
