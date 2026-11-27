<?php

declare(strict_types=1);

use App\Models\Host;
use App\Models\Image;
use App\Models\IpPool;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Models\VpsPlanChange;
use App\Models\VpsPlanVersion;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\VmProvisioningService;
use App\Services\Vps\ResourceReservationService;
use App\States\Host\Ok as HostOk;
use App\States\User\Active as UserActive;
use App\States\VpsPlanChange\Applying;
use App\States\VpsPlanChange\Completed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('sums positive resource deltas across active plan changes for a user and excludes terminal or specified changes', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $host = Host::factory()->create();
    $targetVersion = VpsPlanVersion::factory()->published()->create();

    $vm1 = VirtualMachine::factory()->create(['user_id' => $user->id, 'host_id' => $host->id]);
    $vm2 = VirtualMachine::factory()->create(['user_id' => $user->id, 'host_id' => $host->id]);
    $vm3 = VirtualMachine::factory()->create(['user_id' => $user->id, 'host_id' => $host->id]);
    $vm4 = VirtualMachine::factory()->create(['user_id' => $user->id, 'host_id' => $host->id]);
    $otherVm = VirtualMachine::factory()->create(['user_id' => $otherUser->id, 'host_id' => $host->id]);

    // Pending: +2 CPU, 0 RAM (downgrade from 4096 to 2048 clamped to 0), +20 Disk
    $pendingChange = VpsPlanChange::factory()->create([
        'virtual_machine_id' => $vm1->id,
        'user_id' => $user->id,
        'to_version_id' => $targetVersion->id,
        'old_resources' => ['cpu_cores' => 2, 'ram_mb' => 4096, 'disk_gb' => 20],
        'new_resources' => ['cpu_cores' => 4, 'ram_mb' => 2048, 'disk_gb' => 40],
    ]);

    // AwaitingAuthorization: +1 CPU, +1024 RAM, 0 Disk
    VpsPlanChange::factory()->awaitingAuthorization()->create([
        'virtual_machine_id' => $vm2->id,
        'user_id' => $user->id,
        'to_version_id' => $targetVersion->id,
        'old_resources' => ['cpu_cores' => 1, 'ram_mb' => 1024, 'disk_gb' => 20],
        'new_resources' => ['cpu_cores' => 2, 'ram_mb' => 2048, 'disk_gb' => 20],
    ]);

    // Applying: 0 CPU, +2048 RAM, +10 Disk
    VpsPlanChange::factory()->applying()->create([
        'virtual_machine_id' => $vm3->id,
        'user_id' => $user->id,
        'to_version_id' => $targetVersion->id,
        'old_resources' => ['cpu_cores' => 2, 'ram_mb' => 2048, 'disk_gb' => 30],
        'new_resources' => ['cpu_cores' => 2, 'ram_mb' => 4096, 'disk_gb' => 40],
    ]);

    // ReconciliationRequired: +4 CPU, 0 RAM, +5 Disk
    VpsPlanChange::factory()->reconciliationRequired()->create([
        'virtual_machine_id' => $vm4->id,
        'user_id' => $user->id,
        'to_version_id' => $targetVersion->id,
        'old_resources' => ['cpu_cores' => 2, 'ram_mb' => 2048, 'disk_gb' => 10],
        'new_resources' => ['cpu_cores' => 6, 'ram_mb' => 2048, 'disk_gb' => 15],
    ]);

    // Terminal states (Completed, Failed, Cancelled) must be excluded
    VpsPlanChange::factory()->completed()->create([
        'virtual_machine_id' => $vm1->id,
        'user_id' => $user->id,
        'to_version_id' => $targetVersion->id,
        'old_resources' => ['cpu_cores' => 1, 'ram_mb' => 1024, 'disk_gb' => 10],
        'new_resources' => ['cpu_cores' => 8, 'ram_mb' => 8192, 'disk_gb' => 100],
    ]);
    VpsPlanChange::factory()->failed()->create([
        'virtual_machine_id' => $vm2->id,
        'user_id' => $user->id,
        'to_version_id' => $targetVersion->id,
        'old_resources' => ['cpu_cores' => 1, 'ram_mb' => 1024, 'disk_gb' => 10],
        'new_resources' => ['cpu_cores' => 8, 'ram_mb' => 8192, 'disk_gb' => 100],
    ]);
    VpsPlanChange::factory()->cancelled()->create([
        'virtual_machine_id' => $vm3->id,
        'user_id' => $user->id,
        'to_version_id' => $targetVersion->id,
        'old_resources' => ['cpu_cores' => 1, 'ram_mb' => 1024, 'disk_gb' => 10],
        'new_resources' => ['cpu_cores' => 8, 'ram_mb' => 8192, 'disk_gb' => 100],
    ]);

    // Other user's change must be excluded
    VpsPlanChange::factory()->create([
        'virtual_machine_id' => $otherVm->id,
        'user_id' => $otherUser->id,
        'to_version_id' => $targetVersion->id,
        'old_resources' => ['cpu_cores' => 1, 'ram_mb' => 1024, 'disk_gb' => 10],
        'new_resources' => ['cpu_cores' => 10, 'ram_mb' => 16384, 'disk_gb' => 200],
    ]);

    $service = new ResourceReservationService;

    expect($service->getUserReservations($user->id))->toBe([
        'cpu' => 7,
        'ram' => 3072,
        'disk' => 35,
    ]);

    expect($service->getUserReservations($user->id, $pendingChange->id))->toBe([
        'cpu' => 5,
        'ram' => 3072,
        'disk' => 15,
    ]);
});

it('sums host reservations and supports batch host queries', function (): void {
    $user = User::factory()->create();
    $host1 = Host::factory()->create(['name' => 'host-1']);
    $host2 = Host::factory()->create(['name' => 'host-2']);
    $host3 = Host::factory()->create(['name' => 'host-3']);
    $targetVersion = VpsPlanVersion::factory()->published()->create();

    $vmHost1 = VirtualMachine::factory()->create(['user_id' => $user->id, 'host_id' => $host1->id]);
    $vmHost2 = VirtualMachine::factory()->create(['user_id' => $user->id, 'host_id' => $host2->id]);

    $change1 = VpsPlanChange::factory()->create([
        'virtual_machine_id' => $vmHost1->id,
        'user_id' => $user->id,
        'to_version_id' => $targetVersion->id,
        'old_resources' => ['cpu_cores' => 2, 'ram_mb' => 2048, 'disk_gb' => 20],
        'new_resources' => ['cpu_cores' => 6, 'ram_mb' => 6144, 'disk_gb' => 50],
    ]);

    VpsPlanChange::factory()->applying()->create([
        'virtual_machine_id' => $vmHost2->id,
        'user_id' => $user->id,
        'to_version_id' => $targetVersion->id,
        'old_resources' => ['cpu_cores' => 4, 'ram_mb' => 4096, 'disk_gb' => 40],
        'new_resources' => ['cpu_cores' => 4, 'ram_mb' => 8192, 'disk_gb' => 40],
    ]);

    $service = new ResourceReservationService;

    expect($service->getHostReservations($host1->id))->toBe([
        'cpu' => 4,
        'ram' => 4096,
        'disk' => 30,
    ]);

    expect($service->getHostReservations($host1->id, $change1->id))->toBe([
        'cpu' => 0,
        'ram' => 0,
        'disk' => 0,
    ]);

    expect($service->getHostReservationsForHostIds([$host1->id, $host2->id, $host3->id, $host1->id]))->toBe([
        $host1->id => ['cpu' => 4, 'ram' => 4096, 'disk' => 30],
        $host2->id => ['cpu' => 0, 'ram' => 4096, 'disk' => 0],
        $host3->id => ['cpu' => 0, 'ram' => 0, 'disk' => 0],
    ]);

    expect($service->getHostReservationsForHostIds([]))->toBe([]);
});

it('includes active plan-change reservations in VmProvisioningService user quota checks', function (): void {
    Queue::fake();
    HypervisorManager::fake(new FakeHypervisorDriver);

    $user = User::factory()->create(['status' => UserActive::class]);
    UserQuota::query()->create([
        'user_id' => $user->id,
        'max_vms' => 5,
        'max_cpu_cores' => 4,
        'max_ram_mb' => 8192,
        'max_storage_gb' => 100,
        'max_ips' => 5,
        'max_snapshots' => 5,
    ]);

    $host = Host::factory()->create([
        'is_active' => true,
        'status' => HostOk::class,
        'capacity_source' => 'discovered',
        'total_cpu_cores' => 32,
        'total_ram_mb' => 65536,
        'total_storage_gb' => 1000,
    ]);

    IpPool::factory()->create([
        'host_id' => $host->id,
        'active' => true,
        'network_mode' => 'routed',
    ]);

    $image = Image::firstOrCreate(
        ['name' => 'ubuntu/24.04'],
        [
            'source' => 'https://images.linuxcontainers.org',
            'protocol' => 'simplestreams',
            'os_type' => 'linux',
            'enabled' => true,
        ],
    );
    $image->hosts()->syncWithoutDetaching([$host->id]);

    $existingVm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
    ]);

    $targetVersion = VpsPlanVersion::factory()->published()->create();

    // Active change reserves +2 CPU (total committed = 1 + 2 = 3 of 4 max_cpu_cores)
    $change = VpsPlanChange::factory()->create([
        'virtual_machine_id' => $existingVm->id,
        'user_id' => $user->id,
        'to_version_id' => $targetVersion->id,
        'old_resources' => ['cpu_cores' => 1, 'ram_mb' => 1024, 'disk_gb' => 10],
        'new_resources' => ['cpu_cores' => 3, 'ram_mb' => 1024, 'disk_gb' => 10],
    ]);

    $provisioning = app(VmProvisioningService::class);

    // Requesting +2 CPU (3 + 2 = 5 > 4) must fail due to reservation
    expect(fn () => $provisioning->provision($user, [
        'name' => 'blocked-by-reservation',
        'cpu_cores' => 2,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'os_image' => 'ubuntu/24.04',
        'host_id' => $host->id,
        'instance_type' => 'container',
    ]))->toThrow(ValidationException::class);

    // Once the change completes, reservation is released and provisioning succeeds
    $change->status->transitionTo(Applying::class);
    $change->status->transitionTo(Completed::class);

    $vm = $provisioning->provision($user, [
        'name' => 'allowed-after-completion',
        'cpu_cores' => 2,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'os_image' => 'ubuntu/24.04',
        'host_id' => $host->id,
        'instance_type' => 'container',
    ]);

    expect($vm->name)->toBe('allowed-after-completion');

    HypervisorManager::resetFake();
});
