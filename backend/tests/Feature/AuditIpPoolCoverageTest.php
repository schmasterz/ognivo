<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditContext;
use App\DTO\Network\CreateIpPoolDTO;
use App\Models\Host;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\IpManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditIpPoolCoverageTest extends TestCase
{
    use AuditAssertionTrait;
    use RefreshDatabase;

    private Host $host;

    private IpManagementService $ipService;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.allow_fake_driver' => true]);
        HypervisorManager::fake(new FakeHypervisorDriver);

        $this->host = Host::create([
            'name' => 'pool-test-host-'.uniqid(),
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'status' => 'ok',
            'is_active' => true,
        ]);

        $this->ipService = app(IpManagementService::class);
        app(AuditContext::class)->setRequestId('req-ippool-test-1111');
    }

    protected function tearDown(): void
    {
        HypervisorManager::resetFake();
        parent::tearDown();
    }

    public function test_ip_pool_lifecycle_and_preflight_audited(): void
    {
        // 1. ip_pool.created
        $pool = $this->ipService->createPool(new CreateIpPoolDTO(
            name: 'Audit Pool Alpha',
            cidr: '10.80.0.0/24',
            hostId: $this->host->id,
            networkMode: 'routed',
            parentInterface: 'eth0',
            gateway: '10.80.0.1',
            firstUsable: '10.80.0.10',
            lastUsable: '10.80.0.50',
            isPublic: true,
            active: true,
            description: 'Initial pool description',
        ));

        $createdRow = $this->assertAuditRow(
            action: 'ip_pool.created',
            outcome: 'success',
            tenantUserId: null,
            visibility: 'admin',
            requestId: 'req-ippool-test-1111',
        );
        $createdCtx = json_decode((string) $createdRow->context, true);
        $this->assertSame('Audit Pool Alpha', $createdCtx['name']);
        $this->assertSame('10.80.0.0/24', $createdCtx['cidr']);
        $this->assertSame($this->host->id, $createdCtx['host_id']);

        // 2. ip_pool.updated (via Auditable diff)
        $pool->name = 'Audit Pool Renamed';
        $pool->description = 'Updated description';
        $pool->save();

        $updatedRow = $this->assertAuditRow(
            action: 'ip_pool.updated',
            outcome: 'success',
            tenantUserId: null,
            visibility: 'admin',
            requestId: 'req-ippool-test-1111',
        );
        $updatedCtx = json_decode((string) $updatedRow->context, true);
        $this->assertSame('Audit Pool Alpha', $updatedCtx['diff']['name']['old']);
        $this->assertSame('Audit Pool Renamed', $updatedCtx['diff']['name']['new']);
        $this->assertSame('Initial pool description', $updatedCtx['diff']['description']['old']);
        $this->assertSame('Updated description', $updatedCtx['diff']['description']['new']);

        // 3. ip_pool.preflight.run (names and ok flags only, no hints)
        $this->ipService->preflightPool($pool);

        $preflightRow = $this->assertAuditRow(
            action: 'ip_pool.preflight.run',
            outcome: 'success',
            tenantUserId: null,
            visibility: 'admin',
            requestId: 'req-ippool-test-1111',
        );
        $preflightCtx = json_decode((string) $preflightRow->context, true);
        $this->assertIsArray($preflightCtx['checks']);
        foreach ($preflightCtx['checks'] as $check) {
            $this->assertArrayHasKey('name', $check);
            $this->assertArrayHasKey('ok', $check);
            $this->assertArrayNotHasKey('hint', $check);
        }

        // 4. ip_pool.deleted
        $this->ipService->deletePool($pool);

        $deletedRow = $this->assertAuditRow(
            action: 'ip_pool.deleted',
            outcome: 'success',
            tenantUserId: null,
            visibility: 'admin',
            requestId: 'req-ippool-test-1111',
        );
        $deletedCtx = json_decode((string) $deletedRow->context, true);
        $this->assertSame('Audit Pool Renamed', $deletedCtx['name']);
    }
}
