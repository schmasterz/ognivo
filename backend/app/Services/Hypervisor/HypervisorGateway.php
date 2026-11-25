<?php

declare(strict_types=1);

namespace App\Services\Hypervisor;

use App\Exceptions\FingerprintMismatchException;
use App\Exceptions\MissingPinException;
use App\Models\Host;
use App\Models\PanelCertificate;
use App\Models\VirtualMachine;
use App\Services\PanelCertificateService;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * Central gateway service that builds authenticated and server-pinned HTTP clients
 * for communicating with Incus and LXD hypervisors.
 */
class HypervisorGateway
{
    /** @var array<string, CurlMultiHandler> */
    protected array $handlers = [];

    public function __construct(
        protected readonly ?PanelCertificateService $certService = null,
        protected readonly ?TlsPeerCertificateFetcherInterface $tlsPeerFetcher = null,
        protected readonly ?HypervisorManager $manager = null,
    ) {}

    /**
     * Get or create a reusable CurlMultiHandler for the given host to enable HTTP keep-alive and TLS connection pooling.
     */
    public function getHandlerForHost(Host $host): CurlMultiHandler
    {
        $key = ($host->id ?? 'transient').':'.$host->endpoint_url.':'.($host->server_public_key_pin ?? '');

        return $this->handlers[$key] ??= new CurlMultiHandler();
    }

    /**
     * Clear all cached cURL handlers.
     */
    public function resetHandlers(): void
    {
        $this->handlers = [];
    }

    /**
     * Reset the cached cURL handler for a specific host.
     */
    public function resetHostHandler(Host $host): void
    {
        $prefix = (string) ($host->id ?? 'transient').':';
        foreach (array_keys($this->handlers) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                unset($this->handlers[$key]);
            }
        }
    }

    /**
     * Compute curl pinned public key format ("sha256//<base64>") from an X.509 certificate PEM.
     * Extracts the SubjectPublicKeyInfo (SPKI) DER structure and hashes it with SHA-256.
     */
    public static function computePinFromCertificate(string $certificatePem): string
    {
        $trimmed = trim($certificatePem);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Certificate PEM cannot be empty.');
        }

        // Try extracting public key from X.509 certificate or directly from PEM
        $cert = @openssl_x509_read($trimmed);
        $pubKey = $cert !== false ? @openssl_pkey_get_public($cert) : @openssl_pkey_get_public($trimmed);

        if ($pubKey !== false) {
            $details = openssl_pkey_get_details($pubKey);
            if (isset($details['key']) && is_string($details['key'])) {
                $lines = explode("\n", trim($details['key']));
                $filtered = array_filter($lines, static fn (string $line): bool => ! str_starts_with($line, '-----'));
                $der = base64_decode(implode('', $filtered), true);
                if ($der !== false && $der !== '') {
                    return 'sha256//'.base64_encode(hash('sha256', $der, true));
                }
            }
        }

        throw new InvalidArgumentException('Failed to extract SubjectPublicKeyInfo (SPKI) from provided X.509 certificate.');
    }

    /**
     * Build an authenticated and pinned HTTP client for the specified Host.
     *
     * @throws MissingPinException In Operational mode if non-fake host lacks a public key pin
     * @throws FingerprintMismatchException On connection if server public key does not match pin
     * @throws RuntimeException If no usable panel certificate is available or host is invalid
     */
    public function client(
        Host $host,
        ?PanelCertificate $certificate = null,
        int $timeout = 60,
        GatewayMode $mode = GatewayMode::Operational,
        ?int $connectTimeout = null,
    ): PendingRequest {
        $certService = $this->certService ?? app(PanelCertificateService::class);
        $clientCert = $certificate ?? $certService->getActiveCertificate() ?? $certService->getRetiringCertificate();

        if ($clientCert === null || ! $clientCert->isUsable()) {
            throw new RuntimeException('No active or retiring panel certificate available.');
        }

        // Fail-closed enforcement: In operational mode, every non-fake host MUST have a stored pin.
        if ($mode === GatewayMode::Operational && $host->driver !== 'fake') {
            if ($host->server_public_key_pin === null || trim($host->server_public_key_pin) === '') {
                $host->status = 'not_trusted';
                if ($host->exists) {
                    $host->save();
                }

                throw new MissingPinException(sprintf('Host [%s] requires a public key pin in operational mode.', $host->name));
            }
        }

        // Client certificate and key must never touch the disk with loose permissions.
        // Use CURLOPT_SSLCERT_BLOB / CURLOPT_SSLKEY_BLOB through Guzzle's curl options.
        /** @var array{verify: bool, curl: array<int, mixed>, cert?: string, ssl_key?: string} $options */
        $options = [
            'verify' => false,
            'curl' => [],
        ];

        // Apply cryptographic public key pin directly on the request's connection
        if ($host->server_public_key_pin !== null && trim($host->server_public_key_pin) !== '' && $host->driver !== 'fake') {
            $options['curl'][CURLOPT_PINNEDPUBLICKEY] = trim($host->server_public_key_pin);
        }

        if (defined('CURLOPT_SSLCERT_BLOB') && defined('CURLOPT_SSLKEY_BLOB')) {
            $options['curl'][CURLOPT_SSLCERT_BLOB] = $clientCert->certificate;
            $options['curl'][CURLOPT_SSLKEY_BLOB] = ($clientCert->id === $certService->getActiveCertificate()?->id && method_exists($certService, 'getActivePrivateKey'))
                ? ($certService->getActivePrivateKey() ?? $clientCert->private_key)
                : $clientCert->private_key;
        } else {
            // Fallback for environments where BLOB constants are undefined:
            // Create temporary files with mode 0600
            $certFile = tempnam(sys_get_temp_dir(), 'panel_cert_');
            $keyFile = tempnam(sys_get_temp_dir(), 'panel_key_');

            if ($certFile === false || $keyFile === false) {
                throw new RuntimeException('Failed to create secure temporary credentials for mTLS.');
            }

            file_put_contents($certFile, $clientCert->certificate);
            file_put_contents($keyFile, $clientCert->private_key);
            @chmod($certFile, 0600);
            @chmod($keyFile, 0600);

            $options['cert'] = $certFile;
            $options['ssl_key'] = $keyFile;

            register_shutdown_function(static function () use ($certFile, $keyFile): void {
                if (file_exists($certFile)) {
                    @unlink($certFile);
                }
                if (file_exists($keyFile)) {
                    @unlink($keyFile);
                }
            });
        }

        $resolvedConnectTimeout = $connectTimeout ?? (is_numeric(config('hypervisor.connect_timeout')) ? (int) config('hypervisor.connect_timeout') : 10);

        $request = Http::baseUrl($host->endpoint_url)
            ->timeout($timeout)
            ->connectTimeout($resolvedConnectTimeout)
            ->withOptions($options);

        if ($host->token !== null && $host->token !== '') {
            $request->withToken($host->token);
        }

        $request->withMiddleware(function (callable $handler) use ($host) {
            return function (RequestInterface $request, array $options) use ($handler, $host) {
                /** @var PromiseInterface $promise */
                $promise = $handler($request, $options);

                return $promise->otherwise(function (mixed $reason) use ($host) {
                    if ($reason instanceof Throwable) {
                        $msg = $reason->getMessage();
                        $isPinMismatch = str_contains($msg, 'public key does not match pinned public key')
                            || str_contains($msg, 'cURL error 90')
                            || str_contains($msg, 'SSL: public key does not match pinned public key')
                            || (defined('CURLE_SSL_PINNEDPUBKEYNOTMATCH') && $reason->getCode() === CURLE_SSL_PINNEDPUBKEYNOTMATCH);

                        if ($isPinMismatch) {
                            $this->resetHostHandler($host);
                            $host->status = 'fingerprint_mismatch';
                            if ($host->exists) {
                                $host->save();
                            }

                            Log::warning(sprintf('Hypervisor host [%s] public key pin mismatch detected on connection to %s.', $host->name, $host->endpoint_url));

                            throw new FingerprintMismatchException(
                                actualFingerprint: '',
                                expectedFingerprint: '',
                                message: 'Server certificate public key does not match pinned public key.',
                                code: 90,
                                previous: $reason
                            );
                        }

                        throw $reason;
                    }

                    throw new RuntimeException('Unknown hypervisor connection error.');
                });
            };
        });

        $request->setHandler($this->getHandlerForHost($host));

        return $request;
    }

    /**
     * Create a snapshot for the specified instance via its hypervisor driver.
     */
    public function createSnapshot(VirtualMachine $vm, string $name): bool
    {
        $manager = $this->manager ?? app(HypervisorManager::class);

        return $manager->driverForVm($vm)->createSnapshot($vm, $name);
    }

    /**
     * List all snapshots for the specified instance via its hypervisor driver.
     *
     * @return list<array{name: string, created_at: string, size: int|null}>
     */
    public function listSnapshots(VirtualMachine $vm): array
    {
        $manager = $this->manager ?? app(HypervisorManager::class);

        return $manager->driverForVm($vm)->listSnapshots($vm);
    }

    /**
     * Restore the specified instance to a named snapshot via its hypervisor driver.
     */
    public function restoreSnapshot(VirtualMachine $vm, string $name): bool
    {
        $manager = $this->manager ?? app(HypervisorManager::class);

        return $manager->driverForVm($vm)->restoreSnapshot($vm, $name);
    }

    /**
     * Delete a named snapshot from the specified instance via its hypervisor driver.
     */
    public function deleteSnapshot(VirtualMachine $vm, string $name): bool
    {
        $manager = $this->manager ?? app(HypervisorManager::class);

        return $manager->driverForVm($vm)->deleteSnapshot($vm, $name);
    }
}
