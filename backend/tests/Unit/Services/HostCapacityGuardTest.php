<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\HostCapacityGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class HostCapacityGuardTest extends TestCase
{
    use RefreshDatabase;

    private HostCapacityGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new HostCapacityGuard;

        config([
            'provisioning.host_cpu_reserve_cores' => 0,
            'provisioning.host_ram_reserve_mb' => 0,
            'provisioning.host_disk_reserve_gb' => 0,
        ]);
    }

    public function test_host_with_unknown_capacity_is_rejected(): void
    {
        $host = Host::create([
            'name' => 'unknown-host',
            'endpoint_url' => 'https://10.0.0.1:8443',
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 2000,
            'capacity_source' => 'unknown',
        ]);

        $this->assertFalse($this->guard->hasKnownCapacity($host));
        $this->assertFalse($this->guard->canFit($host, 1, 1024, 10));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(__('messages.host.capacity_unknown'));

        $this->guard->check($host, 1, 1024, 10);
    }

    public function test_host_with_zero_totals_has_unknown_capacity(): void
    {
        $host = Host::create([
            'name' => 'zero-host',
            'endpoint_url' => 'https://10.0.0.2:8443',
            'total_cpu_cores' => 0,
            'total_ram_mb' => 0,
            'total_storage_gb' => 0,
            'capacity_source' => 'discovered',
        ]);

        $this->assertFalse($this->guard->hasKnownCapacity($host));
        $this->assertFalse($this->guard->canFit($host, 1, 1024, 10));
    }

    public function test_guard_boundary_exact_fit_and_one_unit_over(): void
    {
        $host = Host::create([
            'name' => 'boundary-host',
            'endpoint_url' => 'https://10.0.0.3:8443',
            'total_cpu_cores' => 4,
            'total_ram_mb' => 4096,
            'total_storage_gb' => 100,
            'capacity_source' => 'discovered',
        ]);

        // Exact fit
        $this->assertTrue($this->guard->canFit($host, 4, 4096, 100));

        // 1 CPU core over
        $this->assertFalse($this->guard->canFit($host, 5, 4096, 100));

        // 1 MB RAM over
        $this->assertFalse($this->guard->canFit($host, 4, 4097, 100));

        // 1 GB Disk over
        $this->assertFalse($this->guard->canFit($host, 4, 4096, 101));
    }

    public function test_overcommit_multipliers_scale_capacity(): void
    {
        $user = User::factory()->create();

        $host = Host::create([
            'name' => 'overcommit-host',
            'endpoint_url' => 'https://10.0.0.4:8443',
            'total_cpu_cores' => 4,
            'total_ram_mb' => 4000,
            'total_storage_gb' => 100,
            'cpu_overcommit' => 2.0,
            'ram_overcommit' => 1.5,
            'disk_overcommit' => 1.25,
            'capacity_source' => 'discovered',
        ]);

        // Overcommitted pool limits: CPU 8.0, RAM 6000.0, Disk 125.0
        $this->assertSame([
            'cpu' => 8.0,
            'ram' => 6000.0,
            'disk' => 125.0,
        ], $this->guard->getMaxAllocatable($host));

        // With 0 committed VMs, single-instance limit is capped at physical capacity (4 / 4000 / 100)
        $this->assertSame([
            'cpu' => 4,
            'ram' => 4000,
            'disk' => 100,
        ], $this->guard->getMaxAllocatableForSingleInstance($host));

        // A single VM exceeding physical hardware limits is rejected even though pool overcommit > 1.0
        $this->assertFalse($this->guard->canFit($host, 5, 1000, 50));
        $this->assertFalse($this->guard->canFit($host, 8, 1000, 50));
        $this->assertFalse($this->guard->canFit($host, 2, 4001, 50));
        $this->assertFalse($this->guard->canFit($host, 2, 1000, 101));

        // Multiple VMs within the single-instance physical cap can utilize the overcommitted pool
        VirtualMachine::create([
            'user_id' => $user->id,
            'host_id' => $host->id,
            'name' => 'overcommit-vm-1',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'running',
            'cpu_cores' => 4,
            'ram_mb' => 3000,
            'disk_gb' => 60,
            'os_image' => 'ubuntu:24.04',
        ]);

        // Remaining pool: 4 CPU, 3000 RAM, 65 Disk (all within physical limits 4 / 4000 / 100)
        $this->assertSame([
            'cpu' => 4,
            'ram' => 3000,
            'disk' => 65,
        ], $this->guard->getMaxAllocatableForSingleInstance($host));

        $this->assertTrue($this->guard->canFit($host, 4, 3000, 65));
        $this->assertFalse($this->guard->canFit($host, 5, 3000, 65));
        $this->assertFalse($this->guard->canFit($host, 4, 3001, 65));
        $this->assertFalse($this->guard->canFit($host, 4, 3000, 66));
    }

    public function test_reserves_reduce_allocatable_capacity(): void
    {
        config([
            'provisioning.host_cpu_reserve_cores' => 1,
            'provisioning.host_ram_reserve_mb' => 1024,
            'provisioning.host_disk_reserve_gb' => 20,
        ]);

        $host = Host::create([
            'name' => 'reserve-host',
            'endpoint_url' => 'https://10.0.0.5:8443',
            'total_cpu_cores' => 4,
            'total_ram_mb' => 4096,
            'total_storage_gb' => 100,
            'capacity_source' => 'discovered',
        ]);

        // Effective limits: CPU 3 (4 - 1), RAM 3072 (4096 - 1024), Disk 80 (100 - 20)
        $this->assertTrue($this->guard->canFit($host, 3, 3072, 80));
        $this->assertFalse($this->guard->canFit($host, 4, 3072, 80));
        $this->assertFalse($this->guard->canFit($host, 3, 3073, 80));
        $this->assertFalse($this->guard->canFit($host, 3, 3072, 81));
    }

    public function test_provisioning_vms_counted_while_failed_and_deleted_excluded(): void
    {
        $user = User::factory()->create();

        $host = Host::create([
            'name' => 'accounting-host',
            'endpoint_url' => 'https://10.0.0.6:8443',
            'total_cpu_cores' => 10,
            'total_ram_mb' => 10240,
            'total_storage_gb' => 200,
            'capacity_source' => 'discovered',
        ]);

        // Active running VM: 2 CPU, 2048 RAM, 20 Disk
        VirtualMachine::create([
            'user_id' => $user->id,
            'host_id' => $host->id,
            'name' => 'running-vm',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'running',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu:24.04',
        ]);

        // VM in Provisioning state: 3 CPU, 3072 RAM, 30 Disk (MUST be counted!)
        VirtualMachine::create([
            'user_id' => $user->id,
            'host_id' => $host->id,
            'name' => 'provisioning-vm',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'cpu_cores' => 3,
            'ram_mb' => 3072,
            'disk_gb' => 30,
            'os_image' => 'ubuntu:24.04',
        ]);

        // Failed VM: 4 CPU, 4096 RAM, 50 Disk (MUST be excluded)
        VirtualMachine::create([
            'user_id' => $user->id,
            'host_id' => $host->id,
            'name' => 'failed-vm',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'failed',
            'cpu_cores' => 4,
            'ram_mb' => 4096,
            'disk_gb' => 50,
            'os_image' => 'ubuntu:24.04',
        ]);

        // Deleted VM: 4 CPU, 4096 RAM, 50 Disk (MUST be excluded)
        VirtualMachine::create([
            'user_id' => $user->id,
            'host_id' => $host->id,
            'name' => 'deleted-vm',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'deleted',
            'cpu_cores' => 4,
            'ram_mb' => 4096,
            'disk_gb' => 50,
            'os_image' => 'ubuntu:24.04',
        ]);

        $committed = $this->guard->getCommittedUsage($host);
        // Sum of running (2, 2048, 20) + provisioning (3, 3072, 30) = 5 CPU, 5120 RAM, 50 Disk
        $this->assertSame(5, $committed['cpu']);
        $this->assertSame(5120, $committed['ram']);
        $this->assertSame(50, $committed['disk']);

        // Remaining capacity: 5 CPU, 5120 RAM, 150 Disk
        $this->assertTrue($this->guard->canFit($host, 5, 5120, 150));
        $this->assertFalse($this->guard->canFit($host, 6, 5120, 150));
    }
}
