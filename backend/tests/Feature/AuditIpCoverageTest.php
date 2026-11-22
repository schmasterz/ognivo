<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditContext;
use App\Models\Host;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\IpManagementService;
use App\States\Vm\Running;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AuditIpCoverageTest extends TestCase
{
    use AuditAssertionTrait;
    use RefreshDatabase;

    private User $tenant;

    private Host $host;

    private IpPool $pool;

    private IpManagementService $ipService;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->tenant = User::factory()->create(['status' => 'active']);
        $this->tenant->assignRole('customer');

        $this->host = Host::create([
            'name' => 'ip-test-host-'.uniqid(),
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'status' => 'ok',
            'is_active' => true,
        ]);

        $this->pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'IP Pool '.uniqid(),
            'cidr' => '10.70.0.0/24',
            'first_usable' => '10.70.0.10',
            'last_usable' => '10.70.0.50',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
            'is_public' => true,
        ]);

        $this->ipService = app(IpManagementService::class);
        app(AuditContext::class)->setRequestId('req-ip-test-4321');
    }

    public function test_ip_allocated_and_released(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'vm-1-'.uniqid(),
            'incus_project' => 'user-'.$this->tenant->id,
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        // 1. IP Allocated
        $allocation = $this->ipService->allocateIp($this->pool, $vm, $this->tenant);

        $allocRow = $this->assertAuditRow(
            action: 'ip.allocated',
            outcome: 'success',
            tenantUserId: $this->tenant->id,
            visibility: 'tenant',
            requestId: 'req-ip-test-4321',
        );
        $allocCtx = json_decode((string) $allocRow->context, true);
        $this->assertSame($allocation->ip_string, $allocCtx['ip']);
        $this->assertSame($vm->id, $allocCtx['virtual_machine_id']);
        $this->assertSame($this->pool->id, $allocCtx['pool_id']);

        // 2. IP Released
        $this->ipService->emergencyReleaseAllocation($allocation);

        $releaseRow = $this->assertAuditRow(
            action: 'ip.released',
            outcome: 'success',
            tenantUserId: $this->tenant->id,
            visibility: 'tenant',
            requestId: 'req-ip-test-4321',
        );
        $releaseCtx = json_decode((string) $releaseRow->context, true);
        $this->assertSame($allocation->ip_string, $releaseCtx['ip']);
        $this->assertSame($this->pool->id, $releaseCtx['pool_id']);
    }
}
