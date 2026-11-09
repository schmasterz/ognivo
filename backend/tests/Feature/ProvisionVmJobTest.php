<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\AdminVirtualMachineStatusUpdated;
use App\Events\VirtualMachineStatusUpdated;
use App\Jobs\ProvisionVmJob;
use App\Models\Host;
use App\Models\IpPool;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\IpManagementService;
use App\States\Vm\Failed;
use App\States\Vm\Running;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ProvisionVmJobTest extends TestCase
{
    use RefreshDatabase;

    private Host $host;

    private User $user;

    private IpPool $pool;

    private FakeHypervisorDriver $driver;

    private HypervisorManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.allow_fake_driver' => true]);
        $this->driver = new FakeHypervisorDriver;
        HypervisorManager::fake($this->driver);
        $this->manager = app(HypervisorManager::class);

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->host = Host::create([
            'name' => 'test-node-'.uniqid(),
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'status' => 'ok',
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 1000,
        ]);

        $this->user = User::factory()->create(['status' => 'active']);

        UserQuota::create([
            'user_id' => $this->user->id,
            'max_vms' => 5,
            'max_cpu_cores' => 16,
            'max_ram_mb' => 32768,
            'max_storage_gb' => 500,
            'max_ips' => 5,
        ]);

        $this->pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Test Pool '.uniqid(),
            'cidr' => '10.50.0.0/24',
            'first_usable' => '10.50.0.10',
            'last_usable' => '10.50.0.50',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
            'active' => true,
            'is_public' => true,
        ]);
    }

    private function createVmWithIp(string $name = 'test-vm'): array
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => $name.'-'.uniqid(),
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu/24.04',
        ]);

        $allocation = app(IpManagementService::class)->allocateIp($this->pool, $vm, $this->user);

        return [$vm, $allocation];
    }

    public function test_observed_equals_assigned_marks_network_status_observed(): void
    {
        /** @var VirtualMachine $vm */
        [$vm, $allocation] = $this->createVmWithIp('vm-observed');

        $this->driver->setInstanceStatus($vm, 'Running');
        $this->driver->setInstanceState($vm, [
            'status' => 'Running',
            'network' => [
                'eth0' => [
                    'addresses' => [
                        [
                            'family' => 'inet',
                            'address' => $vm->ipv4_address,
                            'netmask' => '32',
                            'scope' => 'global',
                        ],
                    ],
                ],
            ],
        ]);

        $job = new ProvisionVmJob($vm, $allocation->id);
        $job->handle($this->manager);

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Running::class));
        $this->assertSame('observed', $vm->network_status);
    }

    public function test_observed_not_equals_assigned_marks_mismatch_and_vm_running(): void
    {
        /** @var VirtualMachine $vm */
        [$vm, $allocation] = $this->createVmWithIp('vm-mismatch');

        $this->driver->setInstanceStatus($vm, 'Running');
        $this->driver->setInstanceState($vm, [
            'status' => 'Running',
            'network' => [
                'eth0' => [
                    'addresses' => [
                        [
                            'family' => 'inet',
                            'address' => '192.168.1.199',
                            'netmask' => '32',
                            'scope' => 'global',
                        ],
                    ],
                ],
            ],
        ]);

        $job = new ProvisionVmJob($vm, $allocation->id);
        $job->handle($this->manager);

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Running::class));
        $this->assertSame('mismatch', $vm->network_status);
    }

    public function test_permanent_create_failure_marks_failed_cools_allocation_and_deletes_instance(): void
    {
        /** @var VirtualMachine $vm */
        [$vm, $allocation] = $this->createVmWithIp('vm-perm-fail');

        // Status Unknown and creation fails permanently
        $this->driver->setInstanceStatus($vm, 'Unknown');
        $this->driver->setFailAction('createInstance', true);

        $job = new ProvisionVmJob($vm, $allocation->id);

        // Permanent failure must NOT throw
        $job->handle($this->manager);

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Failed::class));
        $this->assertSame('unassigned', $vm->network_status);

        $allocation->refresh();
        $this->assertSame('cooling', $allocation->status);
        $this->assertNull($allocation->virtual_machine_id);

        $this->assertTrue($this->driver->wasInstanceDeleted($vm));
    }

    public function test_transient_failure_retried_and_succeeds(): void
    {
        /** @var VirtualMachine $vm */
        [$vm, $allocation] = $this->createVmWithIp('vm-transient');

        $this->driver->setInstanceStatus($vm, 'Unknown');

        // First attempt throws a transient ConnectionException
        $attempts = 0;
        $this->driver->setActionCallback('createInstance', function (VirtualMachine $v) use (&$attempts): bool {
            $attempts++;
            if ($attempts === 1) {
                throw new ConnectionException('Connection to hypervisor socket timed out');
            }

            return true;
        });

        $job = new ProvisionVmJob($vm, $allocation->id);

        // Attempt 1: Transient error rethrown
        try {
            $job->handle($this->manager);
            $this->fail('Expected transient ConnectionException to be rethrown');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }

        // VM must NOT be marked Failed after a transient failure
        $vm->refresh();
        $this->assertFalse($vm->status->equals(Failed::class));

        // Attempt 2: Retry succeeds
        $this->driver->setInstanceStatus($vm, 'Running');
        $this->driver->setInstanceState($vm, [
            'status' => 'Running',
            'network' => [
                'eth0' => [
                    'addresses' => [
                        [
                            'family' => 'inet',
                            'address' => $vm->ipv4_address,
                            'netmask' => '32',
                            'scope' => 'global',
                        ],
                    ],
                ],
            ],
        ]);

        $job->handle($this->manager);

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Running::class));
        $this->assertSame('observed', $vm->network_status);
    }

    public function test_job_payload_restored_from_queue_produces_same_behaviour(): void
    {
        config(['provisioning.network_wait_seconds' => 5]);

        /** @var VirtualMachine $vm */
        [$vm, $allocation] = $this->createVmWithIp('vm-payload');

        $this->driver->setInstanceStatus($vm, 'Running');
        $this->driver->setInstanceState($vm, [
            'status' => 'Running',
            'network' => [],
        ]);

        // Attempt 1: In-memory pollAttempt is initially 0
        $job1 = new ProvisionVmJob($vm, $allocation->id, 0);
        $job1->handle($this->manager);

        $vm->refresh();
        $this->assertNotNull($vm->network_wait_started_at);
        $this->assertSame('assigned', $vm->network_status);

        // Simulate queue re-queue: restored job has original constructor args (pollAttempt=0)
        $serialized = serialize(new ProvisionVmJob($vm->fresh(), $allocation->id, 0));
        /** @var ProvisionVmJob $jobRestored */
        $jobRestored = unserialize($serialized);
        $this->assertSame(0, $jobRestored->pollAttempt);

        // Run before deadline has elapsed -> still waiting, does not mark unverified prematurely
        $jobRestored->handle($this->manager);
        $vm->refresh();
        $this->assertSame('assigned', $vm->network_status);

        // Fast forward network_wait_started_at past the 5s threshold
        $vm->update(['network_wait_started_at' => now()->subSeconds(6)]);

        // Restored job with pollAttempt = 0 still succeeds in marking unverified based on DB timestamp
        $serialized2 = serialize(new ProvisionVmJob($vm->fresh(), $allocation->id, 0));
        /** @var ProvisionVmJob $jobRestored2 */
        $jobRestored2 = unserialize($serialized2);
        $this->assertSame(0, $jobRestored2->pollAttempt);

        $jobRestored2->handle($this->manager);

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Running::class));
        $this->assertSame('unverified', $vm->network_status);
    }

    public function test_broadcast_of_network_status_and_ip_changes_via_model_saved_hook(): void
    {
        Event::fake([
            VirtualMachineStatusUpdated::class,
            AdminVirtualMachineStatusUpdated::class,
        ]);

        [$vm] = $this->createVmWithIp('vm-broadcast');

        // Clear events from creation
        Event::fake([
            VirtualMachineStatusUpdated::class,
            AdminVirtualMachineStatusUpdated::class,
        ]);

        $vm->network_status = 'observed';
        $vm->save();

        Event::assertDispatched(VirtualMachineStatusUpdated::class, function (VirtualMachineStatusUpdated $event) use ($vm) {
            return $event->vm['id'] === $vm->id && ($event->vm['network_status'] ?? null) === 'observed';
        });

        Event::assertDispatched(AdminVirtualMachineStatusUpdated::class, function (AdminVirtualMachineStatusUpdated $event) use ($vm) {
            return $event->vm['id'] === $vm->id && ($event->vm['network_status'] ?? null) === 'observed';
        });
    }
}
