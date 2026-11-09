<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\Cast;
use App\DTO\Incus\IncusConnectionResult;
use App\Exceptions\FingerprintMismatchException;
use App\Exceptions\MissingPinException;
use App\Models\Host;
use App\Models\PanelCertificate;
use App\Services\Hypervisor\GatewayMode;
use App\Services\Hypervisor\HypervisorGateway;
use App\Services\Hypervisor\TlsPeerCertificateFetcher;
use App\Services\Hypervisor\TlsPeerCertificateFetcherInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Service verifying mTLS connectivity and cryptographic trust against an Incus host.
 */
final class IncusConnectionChecker
{
    public function __construct(
        protected readonly PanelCertificateService $certService,
        protected readonly TlsPeerCertificateFetcherInterface $tlsPeerFetcher = new TlsPeerCertificateFetcher,
        protected readonly ?HypervisorGateway $gateway = null,
    ) {}

    /**
     * Check connection and trust status for an existing saved Host.
     */
    public function check(Host $host): IncusConnectionResult
    {
        $startTime = microtime(true);

        // 1. Emulated driver for testing
        if ($host->driver === 'fake') {
            $latencyMs = random_int(8, 20);
            $result = new IncusConnectionResult(
                state: IncusConnectionResult::STATE_OK,
                success: true,
                message: 'Connected and trusted (emulated host).',
                server: $host->driver,
                serverVersion: '1.0-mock',
                latencyMs: $latencyMs,
                isTrusted: true,
                auth: 'trusted',
                details: ['driver' => 'fake', 'endpoint' => $host->endpoint_url]
            );

            $updateData = [
                'status' => IncusConnectionResult::STATE_OK,
                'last_checked_at' => now(),
            ];

            $activeCert = $this->certService->getActiveCertificate();
            if ($activeCert !== null && ! $activeCert->isExpired()) {
                $updateData['needs_retrust'] = false;
            }

            $host->update($updateData);

            return $result;
        }

        // 2. Ensure a usable panel client certificate exists
        $clientCert = $this->certService->getActiveCertificate() ?? $this->certService->getRetiringCertificate();
        if ($clientCert === null || ! $clientCert->isUsable()) {
            $result = new IncusConnectionResult(
                state: IncusConnectionResult::STATE_ERROR,
                success: false,
                message: 'No active panel certificate available. Please generate one before connecting.'
            );

            $host->update([
                'status' => IncusConnectionResult::STATE_ERROR,
                'last_checked_at' => now(),
            ]);

            return $result;
        }

        // 3. Perform mTLS GET /1.0 request with strict verification via HypervisorGateway
        return $this->executeMtlsCheck($host, $clientCert, $startTime);
    }

    /**
     * Perform verification for a dynamic endpoint before saving.
     */
    public function checkDynamic(
        string $endpointUrl,
        ?string $expectedFingerprint = null,
        ?string $serverCertificate = null,
        ?string $driver = 'incus'
    ): IncusConnectionResult {
        $startTime = microtime(true);

        if ($driver === 'fake') {
            return new IncusConnectionResult(
                state: IncusConnectionResult::STATE_OK,
                success: true,
                message: 'Connected and trusted (emulated host).',
                server: $driver,
                serverVersion: '1.0-mock',
                latencyMs: 12,
                isTrusted: true,
                auth: 'trusted',
                details: ['driver' => 'fake', 'endpoint' => $endpointUrl]
            );
        }

        $clientCert = $this->certService->getActiveCertificate() ?? $this->certService->getRetiringCertificate();
        if ($clientCert === null || ! $clientCert->isUsable()) {
            return new IncusConnectionResult(
                state: IncusConnectionResult::STATE_ERROR,
                success: false,
                message: 'No active panel certificate available. Please generate one before connecting.'
            );
        }

        $pin = null;
        if ($serverCertificate !== null && trim($serverCertificate) !== '') {
            try {
                $pin = HypervisorGateway::computePinFromCertificate($serverCertificate);
            } catch (Throwable) {
                // Invalid or untrusted certificate format
            }
        }

        $dynamicHost = new Host([
            'endpoint_url' => $endpointUrl,
            'server_fingerprint' => $expectedFingerprint,
            'server_certificate' => $serverCertificate,
            'server_public_key_pin' => $pin,
            'driver' => $driver ?? 'incus',
        ]);

        return $this->executeMtlsCheck($dynamicHost, $clientCert, $startTime);
    }

    /**
     * Execute mTLS request to GET /1.0 using HypervisorGateway.
     */
    protected function executeMtlsCheck(
        Host $host,
        PanelCertificate $clientCert,
        float $startTime
    ): IncusConnectionResult {
        $gateway = $this->gateway ?? app(HypervisorGateway::class);

        try {
            $client = $gateway->client($host, $clientCert, timeout: 8, mode: GatewayMode::Onboarding);
            /** @var Response $response */
            $response = $client->timeout(8)->get('/1.0');

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000.0);

            if (! $response->successful()) {
                $result = new IncusConnectionResult(
                    state: IncusConnectionResult::STATE_ERROR,
                    success: false,
                    message: sprintf('Server responded with HTTP error %d', $response->status()),
                    latencyMs: $latencyMs
                );
            } else {
                /** @var array<string, mixed> $data */
                $data = $response->json() ?? [];
                /** @var array<string, mixed> $metadata */
                $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];
                $auth = Cast::string($metadata['auth'] ?? 'untrusted', 'untrusted');
                /** @var array<string, mixed> $environment */
                $environment = isset($metadata['environment']) && is_array($metadata['environment']) ? $metadata['environment'] : [];
                $server = Cast::string($environment['server'] ?? 'incus', 'incus');
                $version = Cast::string($environment['server_version'] ?? 'unknown', 'unknown');

                if ($auth === 'trusted') {
                    $result = new IncusConnectionResult(
                        state: IncusConnectionResult::STATE_OK,
                        success: true,
                        message: sprintf('Connected and trusted on %s (v%s).', ucfirst($server), $version),
                        server: $server,
                        serverVersion: $version,
                        latencyMs: $latencyMs,
                        isTrusted: true,
                        auth: 'trusted',
                        details: [
                            'auth' => 'trusted',
                            'server' => $server,
                            'version' => $version,
                        ]
                    );
                } else {
                    $result = new IncusConnectionResult(
                        state: IncusConnectionResult::STATE_NOT_TRUSTED,
                        success: true,
                        message: 'The server answered, but the panel certificate is not in its trust list. Add it again with a token or certificate.',
                        server: $server,
                        serverVersion: $version,
                        latencyMs: $latencyMs,
                        isTrusted: false,
                        auth: 'untrusted',
                        details: [
                            'auth' => 'untrusted',
                            'server' => $server,
                            'version' => $version,
                        ]
                    );
                }
            }
        } catch (FingerprintMismatchException $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000.0);
            $msg = $e->getMessage() !== ''
                ? $e->getMessage()
                : 'Warning: Server certificate does not match the pinned certificate / public key.';

            $result = new IncusConnectionResult(
                state: IncusConnectionResult::STATE_FINGERPRINT_MISMATCH,
                success: false,
                message: $msg,
                latencyMs: $latencyMs,
                details: [
                    'mismatch' => true,
                ]
            );
        } catch (MissingPinException $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000.0);
            $result = new IncusConnectionResult(
                state: IncusConnectionResult::STATE_NOT_TRUSTED,
                success: false,
                message: 'Host is missing required public key pin.',
                latencyMs: $latencyMs,
                details: ['missing_pin' => true]
            );
        } catch (ConnectionException $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000.0);
            $isPinMismatch = str_contains($e->getMessage(), 'public key does not match pinned public key')
                || str_contains($e->getMessage(), 'cURL error 90');

            if ($isPinMismatch) {
                $result = new IncusConnectionResult(
                    state: IncusConnectionResult::STATE_FINGERPRINT_MISMATCH,
                    success: false,
                    message: 'Warning: Server certificate does not match the pinned certificate / public key.',
                    latencyMs: $latencyMs,
                    details: [
                        'mismatch' => true,
                    ]
                );
            } else {
                $result = new IncusConnectionResult(
                    state: IncusConnectionResult::STATE_UNREACHABLE,
                    success: false,
                    message: 'Host unreachable: Connection refused ('.$this->sanitizeErrorMessage($e->getMessage()).')',
                    latencyMs: $latencyMs
                );
            }
        } catch (Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000.0);
            $isUnreachable = str_contains(strtolower($e->getMessage()), 'connection refused')
                || str_contains(strtolower($e->getMessage()), 'failed to connect')
                || str_contains(strtolower($e->getMessage()), 'timed out');

            $state = $isUnreachable ? IncusConnectionResult::STATE_UNREACHABLE : IncusConnectionResult::STATE_ERROR;
            $msg = $isUnreachable
                ? 'Host unreachable: '.$this->sanitizeErrorMessage($e->getMessage())
                : 'Connection check failed: '.$this->sanitizeErrorMessage($e->getMessage());

            $result = new IncusConnectionResult(
                state: $state,
                success: false,
                message: $msg,
                latencyMs: $latencyMs
            );
        }

        if ($host->exists) {
            $updateData = [
                'status' => $result->state,
                'last_checked_at' => now(),
            ];

            $activeCert = $this->certService->getActiveCertificate();
            $isUsingActive = $activeCert !== null
                && $activeCert->id === $clientCert->id
                && $clientCert->status === PanelCertificate::STATUS_ACTIVE
                && ! $clientCert->isExpired();

            if ($result->state === IncusConnectionResult::STATE_OK && $result->auth === 'trusted' && $isUsingActive) {
                $updateData['needs_retrust'] = false;
            }

            $host->update($updateData);
        }

        return $result;
    }

    /**
     * Sanitize error message to ensure no secrets or stack traces leak.
     */
    protected function sanitizeErrorMessage(string $msg): string
    {
        // Strip out paths, tokens, and raw keys
        $clean = preg_replace('#(/[\w.-]+)+#', '[path]', $msg) ?? $msg;
        $clean = preg_replace('/eyJ[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+/', '[token]', $clean) ?? $clean;

        return mb_substr($clean, 0, 200);
    }
}
