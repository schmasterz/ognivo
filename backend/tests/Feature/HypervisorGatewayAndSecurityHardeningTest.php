<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTO\Incus\IncusConnectionResult;
use App\Exceptions\FingerprintMismatchException;
use App\Exceptions\MissingPinException;
use App\Models\Host;
use App\Models\PanelCertificate;
use App\Services\Hypervisor\Drivers\IncusDriver;
use App\Services\Hypervisor\GatewayMode;
use App\Services\Hypervisor\HypervisorGateway;
use App\Services\Hypervisor\TlsPeerCertificateFetcher;
use App\Services\Hypervisor\TlsPeerCertificateFetcherInterface;
use App\Services\PanelCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class HypervisorGatewayAndSecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private PanelCertificate $panelCert;

    protected function setUp(): void
    {
        parent::setUp();

        $certService = app(PanelCertificateService::class);
        $this->panelCert = $certService->generate(365);
    }

    public function test_gateway_builds_client_with_ssl_blobs_and_pinned_public_key_and_no_ca_verification(): void
    {
        $pin = 'sha256//'.base64_encode(str_repeat('x', 32));

        $host = Host::create([
            'name' => 'gateway-node',
            'endpoint_url' => 'https://198.51.100.10:8443',
            'driver' => 'incus',
            'server_public_key_pin' => $pin,
            'token' => 'test-bearer-token',
        ]);

        $gateway = app(HypervisorGateway::class);
        $client = $gateway->client($host, $this->panelCert);

        $this->assertInstanceOf(PendingRequest::class, $client);

        // Inspect options configured on the PendingRequest
        $reflection = new \ReflectionClass($client);
        $optionsProp = $reflection->getProperty('options');
        $optionsProp->setAccessible(true);
        /** @var array<string, mixed> $options */
        $options = $optionsProp->getValue($client);

        // CA validation must be disabled ('verify' => false)
        $this->assertFalse($options['verify'] ?? true, 'CA validation must be disabled in favor of server public key pinning.');

        // CURLOPT_PINNEDPUBLICKEY must be set on the request curl options
        $this->assertArrayHasKey('curl', $options);
        $this->assertEquals($pin, $options['curl'][CURLOPT_PINNEDPUBLICKEY]);

        // Certificate and key must never touch disk with loose permissions; blobs used when supported
        if (defined('CURLOPT_SSLCERT_BLOB') && defined('CURLOPT_SSLKEY_BLOB')) {
            $this->assertEquals($this->panelCert->certificate, $options['curl'][CURLOPT_SSLCERT_BLOB]);
            $this->assertEquals($this->panelCert->private_key, $options['curl'][CURLOPT_SSLKEY_BLOB]);
        }

        // connect_timeout must default to 10 seconds
        $this->assertSame(10, $options['connect_timeout'] ?? null);
    }

    public function test_gateway_configures_custom_connect_timeout_on_pending_request(): void
    {
        $pin = 'sha256//'.base64_encode(str_repeat('y', 32));

        $host = Host::create([
            'name' => 'gateway-custom-timeout-node',
            'endpoint_url' => 'https://198.51.100.15:8443',
            'driver' => 'incus',
            'server_public_key_pin' => $pin,
        ]);

        $gateway = app(HypervisorGateway::class);
        $client = $gateway->client($host, $this->panelCert, connectTimeout: 15);

        /** @var array<string, mixed> $options */
        $options = $client->getOptions();
        $this->assertSame(15, $options['connect_timeout'] ?? null);
    }

    public function test_gateway_uses_config_for_connect_timeout_when_not_explicitly_passed(): void
    {
        config(['hypervisor.connect_timeout' => 25]);

        $pin = 'sha256//'.base64_encode(str_repeat('z', 32));

        $host = Host::create([
            'name' => 'gateway-config-timeout-node',
            'endpoint_url' => 'https://198.51.100.16:8443',
            'driver' => 'incus',
            'server_public_key_pin' => $pin,
        ]);

        $gateway = app(HypervisorGateway::class);
        $client = $gateway->client($host, $this->panelCert);

        /** @var array<string, mixed> $options */
        $options = $client->getOptions();
        $this->assertSame(25, $options['connect_timeout'] ?? null);
    }

    public function test_operational_mode_rejects_missing_pin_and_updates_host_status(): void
    {
        $host = Host::create([
            'name' => 'unpinned-op-node',
            'endpoint_url' => 'https://198.51.100.11:8443',
            'driver' => 'incus',
            'server_public_key_pin' => null,
            'status' => IncusConnectionResult::STATE_OK,
        ]);

        $gateway = app(HypervisorGateway::class);

        try {
            $gateway->client($host, $this->panelCert, mode: GatewayMode::Operational);
            $this->fail('Expected MissingPinException was not thrown.');
        } catch (MissingPinException $e) {
            $this->assertStringContainsString('requires a public key pin in operational mode', $e->getMessage());
        }

        $host->refresh();
        $this->assertEquals(IncusConnectionResult::STATE_NOT_TRUSTED, $host->status->getValue());
    }

    public function test_operational_mode_allows_fake_driver_without_pin(): void
    {
        $host = Host::create([
            'name' => 'fake-node',
            'endpoint_url' => 'https://fake-node.local:8443',
            'driver' => 'fake',
            'server_public_key_pin' => null,
        ]);

        $gateway = app(HypervisorGateway::class);
        $client = $gateway->client($host, $this->panelCert, mode: GatewayMode::Operational);

        $this->assertInstanceOf(PendingRequest::class, $client);
    }

    public function test_onboarding_mode_allows_client_without_pin(): void
    {
        $host = Host::create([
            'name' => 'onboarding-node',
            'endpoint_url' => 'https://198.51.100.12:8443',
            'driver' => 'incus',
            'server_public_key_pin' => null,
            'status' => IncusConnectionResult::STATE_OK,
        ]);

        $gateway = app(HypervisorGateway::class);
        $client = $gateway->client($host, $this->panelCert, mode: GatewayMode::Onboarding);

        $this->assertInstanceOf(PendingRequest::class, $client);
        $host->refresh();
        $this->assertEquals(IncusConnectionResult::STATE_OK, $host->status->getValue());
    }

    /**
     * Prove behaviour with a real local TLS test (do NOT rely on documentation):
     *
     * Verification in this environment confirms that cURL enforces CURLOPT_PINNEDPUBLICKEY
     * directly even when 'verify' => false (which disables CA root certificate verification).
     * When the server presents Certificate A:
     * - Request succeeds with HTTP 200 when CURLOPT_PINNEDPUBLICKEY matches A's SubjectPublicKeyInfo pin.
     * - Request fails with a connection error (cURL error 90: SSL: public key does not match pinned public key)
     *   when CURLOPT_PINNEDPUBLICKEY is set to Certificate B's pin.
     * When the server presents Certificate B:
     * - Request fails with a connection error when CURLOPT_PINNEDPUBLICKEY is set to Certificate A's pin.
     */
    public function test_real_local_tls_server_proves_pinned_public_key_match_and_mismatch_behavior(): void
    {
        config(['hypervisor.allow_private_endpoints' => true]);

        // 1. Generate real cryptographic self-signed certificates and calculate their RFC 7469 SPKI pins
        $certA = $this->generateTlsCertAndKey('127.0.0.1');
        $certB = $this->generateTlsCertAndKey('127.0.0.1');

        $this->assertNotEquals($certA['pin'], $certB['pin']);

        // 2. Start local TLS server with Certificate A
        $serverA = $this->startLocalTlsServer($certA['certPem'], $certA['keyPem'], maxRequests: 2);

        try {
            // (a) Matching Pin: Request succeeds with HTTP 200
            $hostMatch = Host::create([
                'name' => 'real-tls-node-match',
                'endpoint_url' => 'https://'.$serverA['address'],
                'driver' => 'incus',
                'server_public_key_pin' => $certA['pin'],
            ]);

            $gateway = app(HypervisorGateway::class);
            $response = $gateway->client($hostMatch, $this->panelCert)->get('/1.0');

            $this->assertEquals(200, $response->status());
            $this->assertEquals('ok', $response->json('status'));

            // (b) Mismatched Pin (Pin B against Server presenting Cert A): Request fails with connection error
            $hostMismatch = Host::create([
                'name' => 'real-tls-node-mismatch',
                'endpoint_url' => 'https://'.$serverA['address'],
                'driver' => 'incus',
                'server_public_key_pin' => $certB['pin'],
                'status' => IncusConnectionResult::STATE_OK,
            ]);

            try {
                $gateway->client($hostMismatch, $this->panelCert)->get('/1.0');
                $this->fail('Expected connection failure on pinned public key mismatch.');
            } catch (ConnectionException|FingerprintMismatchException $e) {
                // Assert connection failure occurred
                $this->assertTrue(
                    $e instanceof ConnectionException || $e instanceof FingerprintMismatchException,
                    'Failure must be connection error / mismatch exception.'
                );
            }

            // Assert status updated to fingerprint_mismatch
            $hostMismatch->refresh();
            $this->assertEquals(IncusConnectionResult::STATE_FINGERPRINT_MISMATCH, $hostMismatch->status->getValue());
        } finally {
            $this->stopLocalTlsServer($serverA);
        }

        // 3. Start local TLS server with Certificate B and verify client with Pin A fails
        $serverB = $this->startLocalTlsServer($certB['certPem'], $certB['keyPem'], maxRequests: 1);

        try {
            $hostWrongServer = Host::create([
                'name' => 'real-tls-server-b',
                'endpoint_url' => 'https://'.$serverB['address'],
                'driver' => 'incus',
                'server_public_key_pin' => $certA['pin'],
                'status' => IncusConnectionResult::STATE_OK,
            ]);

            $gateway = app(HypervisorGateway::class);

            try {
                $gateway->client($hostWrongServer, $this->panelCert)->get('/1.0');
                $this->fail('Expected connection error when server presents Cert B and client expects Pin A.');
            } catch (ConnectionException|FingerprintMismatchException $e) {
                $this->assertTrue(true);
            }

            $hostWrongServer->refresh();
            $this->assertEquals(IncusConnectionResult::STATE_FINGERPRINT_MISMATCH, $hostWrongServer->status->getValue());
        } finally {
            $this->stopLocalTlsServer($serverB);
        }
    }

    public function test_pin_mismatch_sets_fingerprint_mismatch_status_and_logs_warning_without_leaking_pin(): void
    {
        $certA = $this->generateTlsCertAndKey('127.0.0.1');
        $secretPinB = 'sha256//'.base64_encode('SUPER_SECRET_PIN_DATA_NEVER_LEAK');

        $server = $this->startLocalTlsServer($certA['certPem'], $certA['keyPem'], maxRequests: 1);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message) use ($secretPinB): bool {
                // Must contain host name / endpoint and warning, but NOT leak the secret expected pin!
                return str_contains($message, 'public key pin mismatch detected')
                    && ! str_contains($message, $secretPinB)
                    && ! str_contains($message, 'SUPER_SECRET_PIN_DATA');
            });

        try {
            $host = Host::create([
                'name' => 'leak-test-host',
                'endpoint_url' => 'https://'.$server['address'],
                'driver' => 'incus',
                'server_public_key_pin' => $secretPinB,
                'status' => IncusConnectionResult::STATE_OK,
            ]);

            $gateway = app(HypervisorGateway::class);

            try {
                $gateway->client($host, $this->panelCert)->get('/1.0');
                $this->fail('Expected connection failure on pin mismatch.');
            } catch (ConnectionException|FingerprintMismatchException $e) {
                // Exception message must NOT leak expected pin
                $this->assertStringNotContainsString($secretPinB, $e->getMessage());
                $this->assertStringNotContainsString('SUPER_SECRET_PIN_DATA', $e->getMessage());
            }

            $host->refresh();
            $this->assertEquals(IncusConnectionResult::STATE_FINGERPRINT_MISMATCH, $host->status->getValue());
        } finally {
            $this->stopLocalTlsServer($server);
        }
    }

    public function test_backfill_command_computes_same_pin_as_onboarding_path(): void
    {
        $certData = $this->generateTlsCertAndKey('incus.internal');

        // Onboarding computation path
        $onboardingPin = HypervisorGateway::computePinFromCertificate($certData['certPem']);
        $this->assertEquals($certData['pin'], $onboardingPin);

        // Host in database without pin but with server_certificate
        $host = Host::create([
            'name' => 'existing-legacy-host',
            'endpoint_url' => 'https://198.51.100.70:8443',
            'driver' => 'incus',
            'server_certificate' => $certData['certPem'],
            'server_public_key_pin' => null,
        ]);

        $this->assertNull($host->server_public_key_pin);

        // Run backfill command
        $exitCode = Artisan::call('hosts:backfill-pins');
        $this->assertEquals(0, $exitCode);

        $host->refresh();
        $this->assertNotNull($host->server_public_key_pin);
        $this->assertEquals($onboardingPin, $host->server_public_key_pin);
    }

    public function test_compute_pin_fails_closed_on_invalid_or_corrupted_pem(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Failed to extract SubjectPublicKeyInfo (SPKI) from provided X.509 certificate.');

        HypervisorGateway::computePinFromCertificate("-----BEGIN CERTIFICATE-----\nINVALID_CORRUPTED_BASE64_DATA\n-----END CERTIFICATE-----");
    }

    public function test_no_code_path_calls_old_fetch_then_request_sequence(): void
    {
        $pin = 'sha256//'.base64_encode(str_repeat('m', 32));

        $host = Host::create([
            'name' => 'no-preflight-node',
            'endpoint_url' => 'https://198.51.100.80:8443',
            'driver' => 'incus',
            'server_public_key_pin' => $pin,
        ]);

        // Mock TlsPeerCertificateFetcherInterface with explicit expectation that it is NEVER called
        $mockFetcher = Mockery::mock(TlsPeerCertificateFetcherInterface::class);
        $mockFetcher->shouldReceive('fetchPeerCertificate')->never();

        $gateway = new HypervisorGateway(tlsPeerFetcher: $mockFetcher);
        $client = $gateway->client($host, $this->panelCert, mode: GatewayMode::Operational);

        $this->assertInstanceOf(PendingRequest::class, $client);
    }

    public function test_fetch_peer_certificate_rejects_private_and_loopback_by_default(): void
    {
        config(['hypervisor.allow_private_endpoints' => false]);

        $fetcher = new TlsPeerCertificateFetcher;

        $privateTargets = [
            '127.0.0.1:8443',
            '10.0.0.5:8443',
            '192.168.1.1:8443',
            '172.16.0.10:8443',
            '[::1]:8443',
            '::1',
            '[fc00::1]:8443',
            'localhost',
            'https://127.0.0.1:8443',
        ];

        foreach ($privateTargets as $target) {
            try {
                $fetcher->fetchPeerCertificate($target);
                $this->fail(sprintf('Expected target [%s] to be rejected as private/loopback.', $target));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('are not allowed', $e->getMessage());
            }
        }
    }

    public function test_fetch_peer_certificate_allows_private_endpoints_when_configured(): void
    {
        config(['hypervisor.allow_private_endpoints' => true]);

        $fetcher = new TlsPeerCertificateFetcher;

        try {
            $fetcher->fetchPeerCertificate('127.0.0.1:54321', timeout: 1);
            $this->fail('Expected connection failure on closed port.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('are not allowed', $e->getMessage());
            $this->assertStringContainsString('Failed to connect', $e->getMessage());
        }
    }

    public function test_fetch_peer_certificate_parses_ipv6_literals_correctly(): void
    {
        config(['hypervisor.allow_private_endpoints' => true]);

        $fetcher = new TlsPeerCertificateFetcher;

        try {
            $fetcher->fetchPeerCertificate('[::1]:54321', timeout: 1);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Failed to connect to ::1:54321', $e->getMessage());
        }

        try {
            $fetcher->fetchPeerCertificate('::1', port: 8443, timeout: 1);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Failed to connect to ::1:8443', $e->getMessage());
        }

        try {
            $fetcher->fetchPeerCertificate('https://[::1]:9443/1.0', timeout: 1);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Failed to connect to ::1:9443', $e->getMessage());
        }
    }

    public function test_cleanup_command_removes_leftover_panel_cert_key_files(): void
    {
        $testStorageDir = storage_path('app/lxd-certs');
        if (! is_dir($testStorageDir)) {
            @mkdir($testStorageDir, 0700, true);
        }

        $dummyKey = $testStorageDir.'/panel_cert_9999.key';
        $dummyCrt = $testStorageDir.'/panel_cert_9999.crt';
        file_put_contents($dummyKey, 'dummy-key-content');
        file_put_contents($dummyCrt, 'dummy-crt-content');

        $this->assertFileExists($dummyKey);
        $this->assertFileExists($dummyCrt);

        Artisan::call('hypervisor:cleanup-cert-keys');

        $this->assertFileDoesNotExist($dummyKey);
        $this->assertFileDoesNotExist($dummyCrt);
        $this->assertDirectoryDoesNotExist($testStorageDir);
    }

    public function test_drivers_use_gateway_client(): void
    {
        $host = Host::create([
            'name' => 'driver-test-node',
            'endpoint_url' => 'https://198.51.100.50:8443',
            'driver' => 'incus',
            'server_public_key_pin' => 'sha256//'.base64_encode(str_repeat('d', 32)),
        ]);

        $mockGateway = Mockery::mock(HypervisorGateway::class);
        $mockGateway->shouldReceive('client')
            ->once()
            ->with($host, Mockery::type(PanelCertificate::class))
            ->andReturn(Http::baseUrl($host->endpoint_url));

        $driver = new IncusDriver($host, gateway: $mockGateway);

        $method = new \ReflectionMethod($driver, 'client');
        $method->setAccessible(true);
        $client = $method->invoke($driver);

        $this->assertInstanceOf(PendingRequest::class, $client);
    }

    /**
     * Generate a self-signed TLS certificate and private key, returning its PEM and public key pin.
     *
     * @return array{certPem: string, keyPem: string, pin: string}
     */
    private function generateTlsCertAndKey(string $commonName = '127.0.0.1'): array
    {
        $privkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $commonName], $privkey);
        $cert = openssl_csr_sign($csr, null, $privkey, 365);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($privkey, $keyPem);

        $pin = HypervisorGateway::computePinFromCertificate($certPem);

        return [
            'certPem' => $certPem,
            'keyPem' => $keyPem,
            'pin' => $pin,
        ];
    }

    /**
     * Start a local TLS server on an ephemeral loopback port in a child process.
     *
     * @return array{address: string, pid: int, certFile: string, keyFile: string}
     */
    private function startLocalTlsServer(string $certPem, string $keyPem, int $maxRequests = 1): array
    {
        $certFile = tempnam(sys_get_temp_dir(), 'test_cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'test_key_');
        file_put_contents($certFile, $certPem);
        file_put_contents($keyFile, $keyPem);

        $context = stream_context_create([
            'ssl' => [
                'local_cert' => $certFile,
                'local_pk' => $keyFile,
                'verify_peer' => false,
            ],
        ]);

        $server = stream_socket_server('ssl://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if ($server === false) {
            throw new RuntimeException("Failed to bind local TLS server: $errstr");
        }

        $address = stream_socket_get_name($server, false);

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Failed to fork test server process.');
        }

        if ($pid === 0) {
            for ($i = 0; $i < $maxRequests; $i++) {
                $conn = @stream_socket_accept($server, 10);
                if ($conn) {
                    @fread($conn, 4096);
                    @fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 15\r\nConnection: close\r\n\r\n{\"status\":\"ok\"}");
                    @fclose($conn);
                }
            }
            @fclose($server);
            exit(0);
        }

        @fclose($server);
        usleep(50000);

        return [
            'address' => $address,
            'pid' => $pid,
            'certFile' => $certFile,
            'keyFile' => $keyFile,
        ];
    }

    /**
     * Stop the local TLS server child process and clean up temporary files.
     *
     * @param  array{address: string, pid: int, certFile: string, keyFile: string}  $serverInfo
     */
    private function stopLocalTlsServer(array $serverInfo): void
    {
        @posix_kill($serverInfo['pid'], SIGKILL);
        @pcntl_waitpid($serverInfo['pid'], $status);
        if (file_exists($serverInfo['certFile'])) {
            @unlink($serverInfo['certFile']);
        }
        if (file_exists($serverInfo['keyFile'])) {
            @unlink($serverInfo['keyFile']);
        }
    }
}
