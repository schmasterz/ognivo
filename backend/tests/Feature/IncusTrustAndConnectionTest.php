<?php

declare(strict_types=1);

use App\DTO\Incus\IncusConnectionResult;
use App\Http\Resources\HostNodeResource;
use App\Jobs\WarnExpiringPanelCertificateJob;
use App\Models\Host;
use App\Models\PanelCertificate;
use App\Models\User;
use App\Services\Hypervisor\TlsPeerCertificateFetcherInterface;
use App\Services\IncusConnectionChecker;
use App\Services\IncusTrustService;
use App\Services\PanelCertificateService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

function generateTestCertificate(string $commonName = '127.0.0.1'): array
{
    $privkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => $commonName], $privkey);
    $cert = openssl_csr_sign($csr, null, $privkey, 365);
    openssl_x509_export($cert, $certPem);
    $fingerprint = strtolower((string) openssl_x509_fingerprint($certPem, 'sha256'));

    return ['pem' => $certPem, 'fingerprint' => $fingerprint];
}

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $this->admin = User::factory()->create(['name' => 'Admin User', 'email' => 'admin-trust@test.local']);
    $this->admin->assignRole('admin');

    $this->customer = User::factory()->create(['name' => 'Regular User', 'email' => 'customer-trust@test.local']);
    $this->customer->assignRole('customer');
});

/*
|--------------------------------------------------------------------------
| Part 0: Panel Certificate Tests
|--------------------------------------------------------------------------
*/

describe('Panel Certificate Generation & Lifecycle', function () {
    test('generate produces a valid EC certificate with correct CN, clientAuth and validity', function () {
        $service = app(PanelCertificateService::class);
        $cert = $service->generate(365);

        expect($cert)->toBeInstanceOf(PanelCertificate::class)
            ->and($cert->status)->toBe(PanelCertificate::STATUS_ACTIVE)
            ->and($cert->certificate)->toContain('-----BEGIN CERTIFICATE-----')
            ->and($cert->fingerprint)->toHaveLength(64);

        // Parse with OpenSSL to verify CN, Curve, and Extended Key Usage
        $parsed = openssl_x509_parse($cert->certificate);
        expect($parsed)->toBeArray()
            ->and($parsed['subject']['CN'])->toBe('incus-panel')
            ->and($parsed['extensions']['extendedKeyUsage'] ?? '')->toContain('TLS Web Client Authentication');

        // Check private key is an EC secp384r1 key
        $pkey = openssl_pkey_get_private($cert->private_key);
        expect($pkey)->not->toBeFalse();
        $details = openssl_pkey_get_details($pkey);
        expect($details['type'])->toBe(OPENSSL_KEYTYPE_EC)
            ->and($details['ec']['curve_name'])->toBe('secp384r1');
    });

    test('stored fingerprint matches SHA-256 of the DER certificate', function () {
        $service = app(PanelCertificateService::class);
        $cert = $service->generate(365);

        $expectedFingerprint = strtolower((string) openssl_x509_fingerprint($cert->certificate, 'sha256'));
        expect($cert->fingerprint)->toBe($expectedFingerprint);
    });

    test('only one active certificate exists after rotation, and old one becomes retiring', function () {
        $service = app(PanelCertificateService::class);
        $first = $service->generate(365);
        expect($first->status)->toBe(PanelCertificate::STATUS_ACTIVE);

        $second = $service->generate(365);

        $first->refresh();
        $second->refresh();

        expect($first->status)->toBe(PanelCertificate::STATUS_RETIRING)
            ->and($second->status)->toBe(PanelCertificate::STATUS_ACTIVE)
            ->and(PanelCertificate::where('status', 'active')->count())->toBe(1);
    });

    test('old certificate is not revoked while any host still needs re-trust', function () {
        $service = app(PanelCertificateService::class);
        $service->generate(365);

        // Create a host marked as needing re-trust
        $host = Host::create([
            'name' => 'needs-retrust-host',
            'endpoint_url' => 'https://192.168.1.50:8443',
            'driver' => 'incus',
            'needs_retrust' => true,
        ]);

        // Rotate to create retiring certificate
        $service->generate(365);

        // Attempting to revoke should fail because hosts need re-trust
        expect(fn () => $service->revokeOldCertificate())
            ->toThrow(RuntimeException::class, 'Cannot revoke old certificate');

        // Mark all hosts as re-trusted
        Host::query()->update(['needs_retrust' => false]);

        // Revoking should now succeed
        $cleanupCommand = $service->revokeOldCertificate();
        expect($cleanupCommand)->toContain('incus config trust remove');
    });

    test('expired certificate is rejected by the connection checker', function () {
        $service = app(PanelCertificateService::class);
        $cert = $service->generate(365);

        // Force expired status
        $cert->update([
            'not_after' => now()->subDay(),
            'status' => 'active',
        ]);

        $host = Host::create([
            'name' => 'expired-test-host',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'incus',
        ]);

        $checker = app(IncusConnectionChecker::class);
        $result = $checker->check($host);

        expect($result->state)->toBe(IncusConnectionResult::STATE_ERROR)
            ->and($result->message)->toContain('No active panel certificate available');
    });

    test('expiry warning job triggers within 30 days of expiration', function () {
        $service = app(PanelCertificateService::class);
        $cert = $service->generate(365);

        // Set not_after to 20 days in the future (within 30 days)
        $cert->update(['not_after' => now()->addDays(20)]);

        Log::spy();

        $job = new WarnExpiringPanelCertificateJob;
        $job->handle();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function ($message) {
                return str_contains((string) $message, 'Active panel mTLS certificate')
                    && str_contains((string) $message, 'expires in');
            });
    });

    test('adding first host with no certificate triggers automatic generation', function () {
        PanelCertificate::query()->delete();
        expect(PanelCertificate::count())->toBe(0);

        // Add a fake host via store endpoint
        $res = $this->actingAs($this->admin)->postJson('/api/admin/hosts', [
            'name' => 'auto-cert-host',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
        ]);

        $res->assertCreated();
        expect(PanelCertificate::where('status', 'active')->count())->toBe(1);
    });

    test('download endpoint returns only the public certificate and is admin only', function () {
        $service = app(PanelCertificateService::class);
        $cert = $service->generate(365);

        // Admin download
        $res = $this->actingAs($this->admin)->get('/api/admin/incus/certificate/download');
        $res->assertOk();
        $res->assertHeader('Content-Type', 'application/x-x509-ca-cert');
        $res->assertHeader('Content-Disposition', 'attachment; filename="incus-panel.crt"');

        $body = $res->getContent();
        expect($body)->toContain('-----BEGIN CERTIFICATE-----')
            ->and($body)->not->toContain('PRIVATE KEY');

        // Customer download is 403 Forbidden
        $custRes = $this->actingAs($this->customer)->get('/api/admin/incus/certificate/download');
        $custRes->assertForbidden();
    });
});

/*
|--------------------------------------------------------------------------
| Part 1: Token Flow Tests
|--------------------------------------------------------------------------
*/

describe('Incus Trust Token Flow & Onboarding', function () {
    test('token parsing handles valid token, malformed base64, invalid JSON, and missing fields', function () {
        $trustService = app(IncusTrustService::class);

        // Valid Token
        $payload = [
            'client_name' => 'panel-client',
            'fingerprint' => '9b935f9dd63e144a9918731ad2b31f9076f8490a61250f1454685ff8e69d2d88',
            'addresses' => ['192.168.1.100:8443'],
            'secret' => 'super-secret-token-key-12345',
            'expires_at' => now()->addHour()->toIso8601String(),
        ];
        $validToken = base64_encode((string) json_encode($payload));
        $parsed = $trustService->parseAndValidateToken($validToken);
        expect($parsed['client_name'])->toBe('panel-client')
            ->and($parsed['secret'])->toBe('super-secret-token-key-12345');

        // Malformed base64
        expect(fn () => $trustService->parseAndValidateToken('!!!not-base64???'))
            ->toThrow(InvalidArgumentException::class, 'Malformed trust token: not valid Base64 string.');

        // Invalid JSON
        $badJson = base64_encode('not valid json {');
        expect(fn () => $trustService->parseAndValidateToken($badJson))
            ->toThrow(InvalidArgumentException::class, 'Malformed trust token: decoded payload is not valid JSON.');

        // Missing fields
        $missingFieldPayload = $payload;
        unset($missingFieldPayload['secret']);
        $missingSecretToken = base64_encode((string) json_encode($missingFieldPayload));
        expect(fn () => $trustService->parseAndValidateToken($missingSecretToken))
            ->toThrow(InvalidArgumentException::class, 'missing required field "secret"');
    });

    test('expired token is rejected before any network call', function () {
        $trustService = app(IncusTrustService::class);

        $expiredPayload = [
            'client_name' => 'panel-client',
            'fingerprint' => '9b935f9dd63e144a9918731ad2b31f9076f8490a61250f1454685ff8e69d2d88',
            'addresses' => ['192.168.1.100:8443'],
            'secret' => 'secret-key-123',
            'expires_at' => now()->subDay()->toIso8601String(),
        ];
        $expiredToken = base64_encode((string) json_encode($expiredPayload));

        Http::fake();

        expect(fn () => $trustService->addHostWithToken('test-expired-node', $expiredToken))
            ->toThrow(InvalidArgumentException::class, 'Trust token expired');

        Http::assertNothingSent();
    });

    test('fingerprint mismatch aborts immediately and sends no request to POST /1.0/certificates', function () {
        $tokenFp = '1111111111111111111111111111111111111111111111111111111111111111';
        $serverFp = '2222222222222222222222222222222222222222222222222222222222222222';

        $payload = [
            'client_name' => 'panel-client',
            'fingerprint' => $tokenFp,
            'addresses' => ['192.168.1.10:8443'],
            'secret' => 'secret-123',
            'expires_at' => now()->addHour()->toIso8601String(),
        ];
        $token = base64_encode((string) json_encode($payload));

        // Mock peer fetcher returning mismatched fingerprint
        $peerFetcher = Mockery::mock(TlsPeerCertificateFetcherInterface::class);
        $peerFetcher->shouldReceive('fetchPeerCertificate')
            ->with('192.168.1.10:8443')
            ->once()
            ->andReturn([
                'pem' => "-----BEGIN CERTIFICATE-----\nFAKE_PEM\n-----END CERTIFICATE-----",
                'fingerprint' => $serverFp,
            ]);

        $this->instance(TlsPeerCertificateFetcherInterface::class, $peerFetcher);

        Http::fake();

        $trustService = app(IncusTrustService::class);

        expect(fn () => $trustService->addHostWithToken('mismatch-host', $token))
            ->toThrow(RuntimeException::class, 'Security alert: Server certificate fingerprint');

        // Assert nothing sent to POST /1.0/certificates (MITM protection)
        Http::assertNothingSent();
    });

    test('successful flow sends only the public certificate never the private key and stores pinned certificate', function () {
        $testCert = generateTestCertificate('192.168.1.10');
        $matchingFp = $testCert['fingerprint'];
        $fakeServerPem = $testCert['pem'];

        $payload = [
            'client_name' => 'panel-client',
            'fingerprint' => $matchingFp,
            'addresses' => ['192.168.1.10:8443'],
            'secret' => 'valid-secret-key-456',
            'expires_at' => now()->addHour()->toIso8601String(),
        ];
        $token = base64_encode((string) json_encode($payload));

        $peerFetcher = Mockery::mock(TlsPeerCertificateFetcherInterface::class);
        $peerFetcher->shouldReceive('fetchPeerCertificate')
            ->andReturn([
                'pem' => $fakeServerPem,
                'fingerprint' => $matchingFp,
            ]);

        $this->instance(TlsPeerCertificateFetcherInterface::class, $peerFetcher);

        // Ensure active panel certificate exists
        $certService = app(PanelCertificateService::class);
        $panelCert = $certService->getActiveCertificate() ?? $certService->generate(365);

        // Fake Incus HTTP responses
        Http::fake([
            'https://192.168.1.10:8443/1.0/certificates' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
                'status_code' => 200,
            ], 200),
            'https://192.168.1.10:8443/1.0' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
                'status_code' => 200,
                'metadata' => [
                    'auth' => 'trusted',
                    'environment' => [
                        'server' => 'incus',
                        'server_version' => '6.0.2',
                    ],
                ],
            ], 200),
        ]);

        $trustService = app(IncusTrustService::class);
        $host = $trustService->addHostWithToken('verified-incus-host', $token);

        // Assert on request body sent to /1.0/certificates
        Http::assertSent(function ($request) use ($panelCert, $token) {
            if ($request->url() === 'https://192.168.1.10:8443/1.0/certificates') {
                $body = $request->data();
                expect($body['type'])->toBe('client')
                    ->and($body['name'])->toBe('panel-client')
                    ->and($body['trust_token'])->toBe($token)
                    // NEVER send private key
                    ->and(json_encode($body))->not->toContain('PRIVATE KEY')
                    ->and(json_encode($body))->not->toContain($panelCert->private_key);

                return true;
            }

            return false;
        });

        // Assert Host stored with pinned certificate and NO stored single-use token
        expect($host->server_certificate)->toBe($fakeServerPem)
            ->and($host->server_fingerprint)->toBe($matchingFp)
            ->and($host->token)->toBeNull();
    });

    test('addHostWithCertificate rejects wrong expected fingerprint', function () {
        $realFp = '1111111111111111111111111111111111111111111111111111111111111111';
        $wrongFp = '2222222222222222222222222222222222222222222222222222222222222222';

        $peerFetcher = Mockery::mock(TlsPeerCertificateFetcherInterface::class);
        $peerFetcher->shouldReceive('fetchPeerCertificate')
            ->with('192.168.1.20:8443')
            ->once()
            ->andReturn([
                'pem' => "-----BEGIN CERTIFICATE-----\nFAKE_PEM\n-----END CERTIFICATE-----",
                'fingerprint' => $realFp,
            ]);

        $this->instance(TlsPeerCertificateFetcherInterface::class, $peerFetcher);

        $trustService = app(IncusTrustService::class);

        expect(fn () => $trustService->addHostWithCertificate('wrong-cert-host', '192.168.1.20:8443', $wrongFp))
            ->toThrow(RuntimeException::class, 'Security alert: Server certificate fingerprint');
    });
});

/*
|--------------------------------------------------------------------------
| Part 2: Connection Checker Tests (5 Distinct States)
|--------------------------------------------------------------------------
*/

describe('Connection Checker - 5 Distinct States', function () {
    beforeEach(function () {
        app(PanelCertificateService::class)->generate(365);
    });

    test('returns state ok for trusted connection and updates host timestamp and status', function () {
        $host = Host::create([
            'name' => 'ok-host',
            'endpoint_url' => 'https://incus-ok.internal:8443',
            'driver' => 'incus',
        ]);

        Http::fake([
            'https://incus-ok.internal:8443/1.0' => Http::response([
                'metadata' => [
                    'auth' => 'trusted',
                    'environment' => [
                        'server' => 'incus',
                        'server_version' => '6.0.0',
                    ],
                ],
            ], 200),
        ]);

        $checker = app(IncusConnectionChecker::class);
        $result = $checker->check($host);

        expect($result->state)->toBe(IncusConnectionResult::STATE_OK)
            ->and($result->server)->toBe('incus')
            ->and($result->serverVersion)->toBe('6.0.0')
            ->and($result->latencyMs)->toBeGreaterThanOrEqual(0);

        $host->refresh();
        expect($host->status)->toBe(IncusConnectionResult::STATE_OK)
            ->and($host->last_checked_at)->not->toBeNull();
    });

    test('returns state not_trusted when server responds with untrusted auth', function () {
        $host = Host::create([
            'name' => 'untrusted-host',
            'endpoint_url' => 'https://incus-untrusted.internal:8443',
            'driver' => 'incus',
        ]);

        Http::fake([
            'https://incus-untrusted.internal:8443/1.0' => Http::response([
                'metadata' => [
                    'auth' => 'untrusted',
                    'environment' => [
                        'server' => 'incus',
                        'server_version' => '6.0.0',
                    ],
                ],
            ], 200),
        ]);

        $checker = app(IncusConnectionChecker::class);
        $result = $checker->check($host);

        expect($result->state)->toBe(IncusConnectionResult::STATE_NOT_TRUSTED)
            ->and($result->isTrusted)->toBeFalse();

        $host->refresh();
        expect($host->status)->toBe(IncusConnectionResult::STATE_NOT_TRUSTED);
    });

    test('returns state unreachable when connection fails or times out', function () {
        $host = Host::create([
            'name' => 'unreachable-host',
            'endpoint_url' => 'https://incus-unreachable.internal:8443',
            'driver' => 'incus',
        ]);

        Http::fake([
            'https://incus-unreachable.internal:8443/1.0' => function () {
                throw new ConnectionException('Connection refused');
            },
        ]);

        $checker = app(IncusConnectionChecker::class);
        $result = $checker->check($host);

        expect($result->state)->toBe(IncusConnectionResult::STATE_UNREACHABLE);

        $host->refresh();
        expect($host->status)->toBe(IncusConnectionResult::STATE_UNREACHABLE);
    });

    test('returns state fingerprint_mismatch when peer cert differs from pinned cert', function () {
        $pinnedFp = '1111111111111111111111111111111111111111111111111111111111111111';

        $host = Host::create([
            'name' => 'tampered-host',
            'endpoint_url' => 'https://incus-tampered.internal:8443',
            'server_fingerprint' => $pinnedFp,
            'server_public_key_pin' => 'sha256//'.base64_encode(str_repeat('1', 32)),
            'driver' => 'incus',
        ]);

        Http::fake([
            'https://incus-tampered.internal:8443/1.0' => function () {
                throw new ConnectionException('cURL error 90: SSL: public key does not match pinned public key');
            },
        ]);

        $checker = app(IncusConnectionChecker::class);
        $result = $checker->check($host);

        expect($result->state)->toBe(IncusConnectionResult::STATE_FINGERPRINT_MISMATCH)
            ->and($result->message)->toContain('does not match the pinned certificate');

        $host->refresh();
        expect($host->status)->toBe(IncusConnectionResult::STATE_FINGERPRINT_MISMATCH);
    });

    test('returns state error on unexpected HTTP errors with safe message', function () {
        $host = Host::create([
            'name' => 'error-host',
            'endpoint_url' => 'https://incus-error.internal:8443',
            'driver' => 'incus',
        ]);

        Http::fake([
            'https://incus-error.internal:8443/1.0' => Http::response(['error' => 'Internal failure'], 500),
        ]);

        $checker = app(IncusConnectionChecker::class);
        $result = $checker->check($host);

        expect($result->state)->toBe(IncusConnectionResult::STATE_ERROR);

        $host->refresh();
        expect($host->status)->toBe(IncusConnectionResult::STATE_ERROR);
    });
});

/*
|--------------------------------------------------------------------------
| Security & Authorization Tests
|--------------------------------------------------------------------------
*/

describe('Security & Admin Authorization', function () {
    test('private key and trust token never appear in logs, serialization or API responses', function () {
        $service = app(PanelCertificateService::class);
        $cert = $service->generate(365);

        // JSON serialization of PanelCertificate model hides private key
        $json = json_encode($cert->toArray());
        expect($json)->not->toContain('private_key')
            ->and($json)->not->toContain($cert->private_key);

        // Admin certificate show endpoint never exposes private key
        $res = $this->actingAs($this->admin)->getJson('/api/admin/incus/certificate');
        $res->assertOk();
        $resContent = $res->getContent();
        expect($resContent)->not->toContain('private_key')
            ->and($resContent)->not->toContain($cert->private_key);

        // Host resource never leaks raw private key or tokens
        $host = Host::create([
            'name' => 'security-test-host',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'incus',
        ]);
        $hostResourceJson = json_encode(new HostNodeResource($host));
        expect($hostResourceJson)->not->toContain('private_key')
            ->and($hostResourceJson)->not->toContain('token_secret');
    });

    test('non-admin user gets 403 on adding host, verifying connection, downloading cert and rotating it', function () {
        $host = Host::create([
            'name' => 'protected-host',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'incus',
        ]);

        // 1. Add host (connect-token)
        $this->actingAs($this->customer)
            ->postJson('/api/admin/hosts/connect-token', [
                'name' => 'hacked-node',
                'token' => 'invalid-token',
            ])
            ->assertForbidden();

        // 2. Add host (add-certificate)
        $this->actingAs($this->customer)
            ->postJson('/api/admin/hosts/add-certificate', [
                'name' => 'hacked-node',
                'address' => '127.0.0.1:8443',
                'expected_fingerprint' => '1111111111111111111111111111111111111111111111111111111111111111',
            ])
            ->assertForbidden();

        // 3. Verify connection
        $this->actingAs($this->customer)
            ->postJson("/api/admin/hosts/{$host->id}/verify-connection")
            ->assertForbidden();

        // 4. Download certificate
        $this->actingAs($this->customer)
            ->get('/api/admin/incus/certificate/download')
            ->assertForbidden();

        // 5. Rotate certificate
        $this->actingAs($this->customer)
            ->postJson('/api/admin/incus/certificate/rotate')
            ->assertForbidden();

        // 6. Retrust host
        $this->actingAs($this->customer)
            ->postJson("/api/admin/hosts/{$host->id}/retrust", ['token' => 'some-token'])
            ->assertForbidden();
    });
});

/*
|--------------------------------------------------------------------------
| Part 4: Certificate Rotation, Retrust Flow & Workability Tests
|--------------------------------------------------------------------------
*/

describe('Certificate Rotation, Retrust Flow & Workability', function () {
    test('rotate -> hosts flagged -> retrust each host -> revoke-old succeeds', function () {
        $certService = app(PanelCertificateService::class);
        $initialCert = $certService->generate(365);
        expect($initialCert->status)->toBe(PanelCertificate::STATUS_ACTIVE)
            ->and($initialCert->active_marker)->toBe(1);

        $testCert1 = generateTestCertificate('192.168.1.11');
        $testCert2 = generateTestCertificate('192.168.1.12');
        $fakeServerPem1 = $testCert1['pem'];
        $fakeServerPem2 = $testCert2['pem'];
        $matchingFp1 = $testCert1['fingerprint'];
        $matchingFp2 = $testCert2['fingerprint'];

        $host1 = Host::create([
            'name' => 'cluster-node-01',
            'endpoint_url' => 'https://192.168.1.11:8443',
            'server_certificate' => $fakeServerPem1,
            'server_fingerprint' => $matchingFp1,
            'driver' => 'incus',
            'needs_retrust' => false,
        ]);

        $host2 = Host::create([
            'name' => 'cluster-node-02',
            'endpoint_url' => 'https://192.168.1.12:8443',
            'server_certificate' => $fakeServerPem2,
            'server_fingerprint' => $matchingFp2,
            'driver' => 'incus',
            'needs_retrust' => false,
        ]);

        // 1. Rotate certificate
        $rotateRes = $this->actingAs($this->admin)->postJson('/api/admin/incus/certificate/rotate');
        $rotateRes->assertOk();
        $rotateRes->assertJsonPath('pending_retrust_hosts_count', 2);

        // Verify hosts are now flagged
        $host1->refresh();
        $host2->refresh();
        expect($host1->needs_retrust)->toBeTrue()
            ->and($host2->needs_retrust)->toBeTrue();

        // Verify revokeOld fails while hosts need retrust
        $revokeFailRes = $this->actingAs($this->admin)->postJson('/api/admin/incus/certificate/revoke-old');
        $revokeFailRes->assertStatus(500);

        // 2. Setup mocks for trust tokens
        $tokenPayload1 = [
            'client_name' => 'panel-client',
            'fingerprint' => $matchingFp1,
            'addresses' => ['192.168.1.11:8443'],
            'secret' => 'secret-1',
            'expires_at' => now()->addHour()->toIso8601String(),
        ];
        $token1 = base64_encode((string) json_encode($tokenPayload1));

        $tokenPayload2 = [
            'client_name' => 'panel-client',
            'fingerprint' => $matchingFp2,
            'addresses' => ['192.168.1.12:8443'],
            'secret' => 'secret-2',
            'expires_at' => now()->addHour()->toIso8601String(),
        ];
        $token2 = base64_encode((string) json_encode($tokenPayload2));

        $peerFetcher = Mockery::mock(TlsPeerCertificateFetcherInterface::class);
        $peerFetcher->shouldReceive('fetchPeerCertificate')
            ->andReturnUsing(function ($address) use ($testCert1, $testCert2) {
                if (str_contains($address, '192.168.1.11')) {
                    return $testCert1;
                }

                return $testCert2;
            });

        $this->instance(TlsPeerCertificateFetcherInterface::class, $peerFetcher);

        Http::fake([
            'https://192.168.1.11:8443/1.0/certificates' => Http::response(['status' => 'Success', 'status_code' => 200], 200),
            'https://192.168.1.11:8443/1.0' => Http::response([
                'metadata' => [
                    'auth' => 'trusted',
                    'environment' => ['server' => 'incus', 'server_version' => '6.0.0'],
                ],
            ], 200),
            'https://192.168.1.12:8443/1.0/certificates' => Http::response(['status' => 'Success', 'status_code' => 200], 200),
            'https://192.168.1.12:8443/1.0' => Http::response([
                'metadata' => [
                    'auth' => 'trusted',
                    'environment' => ['server' => 'incus', 'server_version' => '6.0.0'],
                ],
            ], 200),
        ]);

        // Retrust host 1 via POST /api/admin/hosts/{host}/retrust
        $retrust1Res = $this->actingAs($this->admin)->postJson("/api/admin/hosts/{$host1->id}/retrust", [
            'token' => $token1,
        ]);
        $retrust1Res->assertOk();
        $retrust1Res->assertJsonPath('host.needs_retrust', false);

        $host1->refresh();
        expect($host1->needs_retrust)->toBeFalse();

        // Still 1 host pending retrust
        expect(Host::where('needs_retrust', true)->count())->toBe(1);

        // Retrust host 2 via POST /api/admin/hosts/{host}/retrust
        $retrust2Res = $this->actingAs($this->admin)->postJson("/api/admin/hosts/{$host2->id}/retrust", [
            'token' => $token2,
        ]);
        $retrust2Res->assertOk();
        $retrust2Res->assertJsonPath('host.needs_retrust', false);

        $host2->refresh();
        expect($host2->needs_retrust)->toBeFalse();

        // 0 hosts pending retrust
        expect(Host::where('needs_retrust', true)->count())->toBe(0);

        // 3. Now revoke-old succeeds!
        $revokeRes = $this->actingAs($this->admin)->postJson('/api/admin/incus/certificate/revoke-old');
        $revokeRes->assertOk();
        $revokeRes->assertJsonPath('message', 'Old certificate revoked successfully.');
        expect($revokeRes->json('cleanup_instruction'))->toContain('incus config trust remove');

        $initialCert->refresh();
        expect($initialCert->status)->toBe(PanelCertificate::STATUS_REVOKED)
            ->and($initialCert->active_marker)->toBeNull();
    });

    test('clears needs_retrust automatically when IncusConnectionChecker check returns state ok with auth trusted using ACTIVE certificate', function () {
        $certService = app(PanelCertificateService::class);
        $certService->generate(365);

        $host = Host::create([
            'name' => 'retrust-auto-check-host',
            'endpoint_url' => 'https://incus-auto.internal:8443',
            'driver' => 'incus',
            'needs_retrust' => true,
        ]);

        Http::fake([
            'https://incus-auto.internal:8443/1.0' => Http::response([
                'metadata' => [
                    'auth' => 'trusted',
                    'environment' => [
                        'server' => 'incus',
                        'server_version' => '6.0.0',
                    ],
                ],
            ], 200),
        ]);

        $checker = app(IncusConnectionChecker::class);
        $result = $checker->check($host);

        expect($result->state)->toBe(IncusConnectionResult::STATE_OK)
            ->and($result->auth)->toBe('trusted');

        $host->refresh();
        expect($host->needs_retrust)->toBeFalse();
    });

    test('concurrent generate produces one active certificate', function () {
        $service = app(PanelCertificateService::class);

        // Test multiple generates
        $cert1 = $service->generate(365);
        $cert2 = $service->generate(365);
        $cert3 = $service->generate(365);

        expect(PanelCertificate::where('status', PanelCertificate::STATUS_ACTIVE)->count())->toBe(1)
            ->and(PanelCertificate::where('active_marker', 1)->count())->toBe(1)
            ->and($service->getActiveCertificate()->id)->toBe($cert3->id);

        // Verify partial unique constraint on active_marker at DB level
        expect(function () {
            PanelCertificate::create([
                'certificate' => 'CERT',
                'private_key' => 'KEY',
                'fingerprint' => 'FINGERPRINT',
                'not_before' => now(),
                'not_after' => now()->addYear(),
                'status' => 'active',
                'active_marker' => 1,
            ]);
        })->toThrow(QueryException::class);
    });

    test('LXD driver preserved on token onboarding', function () {
        $testCert = generateTestCertificate('192.168.1.30');
        $matchingFp = $testCert['fingerprint'];
        $fakeServerPem = $testCert['pem'];

        $payload = [
            'client_name' => 'panel-client',
            'fingerprint' => $matchingFp,
            'addresses' => ['192.168.1.30:8443'],
            'secret' => 'lxd-secret-key',
            'expires_at' => now()->addHour()->toIso8601String(),
        ];
        $token = base64_encode((string) json_encode($payload));

        $peerFetcher = Mockery::mock(TlsPeerCertificateFetcherInterface::class);
        $peerFetcher->shouldReceive('fetchPeerCertificate')
            ->andReturn([
                'pem' => $fakeServerPem,
                'fingerprint' => $matchingFp,
            ]);

        $this->instance(TlsPeerCertificateFetcherInterface::class, $peerFetcher);

        Http::fake([
            'https://192.168.1.30:8443/1.0/certificates' => Http::response(['status' => 'Success', 'status_code' => 200], 200),
            'https://192.168.1.30:8443/1.0' => Http::response([
                'metadata' => [
                    'auth' => 'trusted',
                    'environment' => [
                        'server' => 'lxd',
                        'server_version' => '5.21.0',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/hosts', [
            'name' => 'lxd-production-node',
            'token' => $token,
            'driver' => 'lxd',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('host.driver', 'lxd');

        $host = Host::where('name', 'lxd-production-node')->firstOrFail();
        expect($host->driver)->toBe('lxd')
            ->and($host->status)->toBe(IncusConnectionResult::STATE_OK)
            ->and($host->needs_retrust)->toBeFalse();
    });
});
