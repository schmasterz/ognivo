<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditContext;
use App\DTO\Host\CreateHostDTO;
use App\Models\Host;
use App\Services\HostNodeService;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\IncusTrustService;
use App\Services\PanelCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditHostCertCoverageTest extends TestCase
{
    use AuditAssertionTrait;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.allow_fake_driver' => true]);
        HypervisorManager::fake(new FakeHypervisorDriver);
        app(AuditContext::class)->setRequestId('req-hostcert-test-7777');
    }

    protected function tearDown(): void
    {
        HypervisorManager::resetFake();
        parent::tearDown();
    }

    public function test_host_lifecycle_and_retrust_audited(): void
    {
        $hostService = app(HostNodeService::class);
        $trustService = app(IncusTrustService::class);

        // 1. host.created
        $host = $hostService->create(new CreateHostDTO(
            name: 'Test Compute Node 1',
            endpointUrl: 'https://192.168.1.100:8443',
            driver: 'fake',
            isActive: true,
            totalCpuCores: 16,
            totalRamMb: 32768,
            totalStorageGb: 500,
            serverFingerprint: 'fp-initial-1234',
        ));

        $createdRow = $this->assertAuditRow(
            action: 'host.created',
            outcome: 'success',
            tenantUserId: null,
            visibility: 'admin',
            requestId: 'req-hostcert-test-7777',
        );
        $createdCtx = json_decode((string) $createdRow->context, true);
        $this->assertSame('Test Compute Node 1', $createdCtx['name']);
        $this->assertSame('192.168.1.100', $createdCtx['endpoint_host']);
        $this->assertArrayNotHasKey('token', $createdCtx);
        $this->assertArrayNotHasKey('certificate', $createdCtx);

        // 2. host.updated (via Auditable)
        $host->name = 'Test Compute Node Renamed';
        $host->total_cpu_cores = 32;
        $host->save();

        $updatedRow = $this->assertAuditRow(
            action: 'host.updated',
            outcome: 'success',
            tenantUserId: null,
            visibility: 'admin',
            requestId: 'req-hostcert-test-7777',
        );
        $updatedCtx = json_decode((string) $updatedRow->context, true);
        $this->assertSame('Test Compute Node 1', $updatedCtx['diff']['name']['old']);
        $this->assertSame('Test Compute Node Renamed', $updatedCtx['diff']['name']['new']);
        $this->assertSame(16, $updatedCtx['diff']['total_cpu_cores']['old']);
        $this->assertSame(32, $updatedCtx['diff']['total_cpu_cores']['new']);

        // 3. host.retrusted
        $tokenPayload = base64_encode(json_encode([
            'client_name' => 'incus-agent',
            'fingerprint' => 'fp-retrust-5678',
            'addresses' => ['192.168.1.100:8443'],
            'secret' => 'super-secret-trust-token-must-not-leak',
            'expires_at' => now()->addHour()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        $trustService->retrustHostWithToken($host, $tokenPayload);

        $retrustedRow = $this->assertAuditRow(
            action: 'host.retrusted',
            outcome: 'success',
            tenantUserId: null,
            visibility: 'admin',
            requestId: 'req-hostcert-test-7777',
        );
        $retrustedCtx = json_decode((string) $retrustedRow->context, true);
        $this->assertSame('192.168.1.100', $retrustedCtx['endpoint_host']);
        $this->assertStringNotContainsString('super-secret-trust-token', (string) $retrustedRow->context);

        // 4. host.deleted
        $hostService->delete($host);

        $deletedRow = $this->assertAuditRow(
            action: 'host.deleted',
            outcome: 'success',
            tenantUserId: null,
            visibility: 'admin',
            requestId: 'req-hostcert-test-7777',
        );
        $deletedCtx = json_decode((string) $deletedRow->context, true);
        $this->assertSame('Test Compute Node Renamed', $deletedCtx['name']);
        $this->assertSame('192.168.1.100', $deletedCtx['endpoint_host']);
    }

    public function test_certificate_lifecycle_audited(): void
    {
        $certService = app(PanelCertificateService::class);

        // 1. cert.generated
        $cert1 = $certService->generate();

        $genRow = $this->assertAuditRow(
            action: 'cert.generated',
            outcome: 'success',
            tenantUserId: null,
            visibility: 'admin',
            requestId: 'req-hostcert-test-7777',
        );
        $genCtx = json_decode((string) $genRow->context, true);
        $this->assertSame($cert1->fingerprint, $genCtx['fingerprint']);
        $this->assertArrayNotHasKey('certificate', $genCtx);
        $this->assertArrayNotHasKey('private_key', $genCtx);

        // 2. cert.downloaded (allowed read endpoint)
        $downloaded = $certService->downloadCertificate();

        $downRow = $this->assertAuditRow(
            action: 'cert.downloaded',
            outcome: 'success',
            tenantUserId: null,
            visibility: 'admin',
            requestId: 'req-hostcert-test-7777',
        );
        $downCtx = json_decode((string) $downRow->context, true);
        $this->assertSame($downloaded->fingerprint, $downCtx['fingerprint']);

        // 3. cert.rotated (calling generate when active cert exists rotates)
        $cert2 = $certService->generate();

        $rotatedRow = $this->assertAuditRow(
            action: 'cert.rotated',
            outcome: 'success',
            tenantUserId: null,
            visibility: 'admin',
            requestId: 'req-hostcert-test-7777',
        );
        $rotatedCtx = json_decode((string) $rotatedRow->context, true);
        $this->assertSame($cert1->fingerprint, $rotatedCtx['previous_fingerprint']);
        $this->assertSame($cert2->fingerprint, $rotatedCtx['new_fingerprint']);

        // 4. cert.revoked (revoking the retiring certificate from rotation)
        $certService->revokeOldCertificate();

        $revokedRow = $this->assertAuditRow(
            action: 'cert.revoked',
            outcome: 'success',
            tenantUserId: null,
            visibility: 'admin',
            requestId: 'req-hostcert-test-7777',
        );
        $revokedCtx = json_decode((string) $revokedRow->context, true);
        $this->assertSame($cert1->fingerprint, $revokedCtx['fingerprint']);
    }
}
