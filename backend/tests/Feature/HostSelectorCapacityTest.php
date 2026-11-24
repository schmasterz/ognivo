<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Host;
use App\Models\Image;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\HostSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class HostSelectorCapacityTest extends TestCase
{
    use RefreshDatabase;

    private HostSelector $selector;

    private Image $image;

    protected function setUp(): void
    {
        parent::setUp();
        $this->selector = app(HostSelector::class);

        config([
            'provisioning.host_cpu_reserve_cores' => 0,
            'provisioning.host_ram_reserve_mb' => 0,
            'provisioning.host_disk_reserve_gb' => 0,
        ]);

        $this->image = Image::firstOrCreate(
            ['name' => 'ubuntu:24.04'],
            [
                'source' => 'images:ubuntu/24.04',
                'enabled' => true,
            ]
        );
    }

    private function createHost(string $name, int $cpu = 4, int $ram = 4096, int $disk = 100, string $source = 'discovered'): Host
    {
        $host = Host::create([
            'name' => $name,
            'endpoint_url' => 'https://10.0.0.'.rand(10, 200).':8443',
            'driver' => 'fake',
            'status' => 'ok',
            'is_active' => true,
            'total_cpu_cores' => $cpu,
            'total_ram_mb' => $ram,
            'total_storage_gb' => $disk,
            'capacity_source' => $source,
            'capacity_synced_at' => now(),
        ]);

        $host->images()->syncWithoutDetaching([$this->image->id]);

        IpPool::create([
            'host_id' => $host->id,
            'name' => 'Pool for '.$name,
            'cidr' => '192.168.'.rand(10, 200).'.0/24',
            'first_usable' => '192.168.1.10',
            'last_usable' => '192.168.1.50',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
            'active' => true,
        ]);

        return $host;
    }

    public function test_select_with_single_host_that_is_full_returns_null(): void
    {
        // Single host with 4 cores, 4096 MB RAM, 100 GB Disk
        $host = $this->createHost('single-full-host', cpu: 4, ram: 4096, disk: 100);

        $user = User::factory()->create();

        // Fill the host completely with an existing VM
        VirtualMachine::create([
            'user_id' => $user->id,
            'host_id' => $host->id,
            'name' => 'filler-vm',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'running',
            'cpu_cores' => 4,
            'ram_mb' => 4096,
            'disk_gb' => 100,
            'os_image' => 'ubuntu:24.04',
        ]);

        // Attempting to select for another VM requiring 1 core should return null (previously returned anyway!)
        $selected = $this->selector->select([
            'os_image' => 'ubuntu:24.04',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
        ]);

        $this->assertNull($selected);
    }

    public function test_select_with_two_hosts_picks_the_one_that_fits(): void
    {
        // Host A is full (4 cores, 4096 MB)
        $hostA = $this->createHost('host-a-full', cpu: 4, ram: 4096, disk: 100);
        // Host B has plenty of capacity (16 cores, 32768 MB)
        $hostB = $this->createHost('host-b-available', cpu: 16, ram: 32768, disk: 500);

        $user = User::factory()->create();

        VirtualMachine::create([
            'user_id' => $user->id,
            'host_id' => $hostA->id,
            'name' => 'filler-vm-a',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'running',
            'cpu_cores' => 4,
            'ram_mb' => 4096,
            'disk_gb' => 100,
            'os_image' => 'ubuntu:24.04',
        ]);

        $selected = $this->selector->select([
            'os_image' => 'ubuntu:24.04',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
        ]);

        $this->assertNotNull($selected);
        $this->assertSame($hostB->id, $selected->id);
    }

    public function test_validate_host_rejects_unknown_capacity_or_insufficient_capacity(): void
    {
        $unknownHost = $this->createHost('unknown-host', cpu: 32, ram: 65536, disk: 2000, source: 'unknown');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(__('messages.host.capacity_unknown'));

        $this->selector->validateHost($unknownHost, [
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
        ]);
    }

    public function test_validate_host_rejects_insufficient_capacity_on_explicit_host(): void
    {
        $smallHost = $this->createHost('small-host', cpu: 2, ram: 2048, disk: 20);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(__('messages.host.insufficient_capacity'));

        $this->selector->validateHost($smallHost, [
            'cpu_cores' => 4,
            'ram_mb' => 4096,
            'disk_gb' => 50,
        ]);
    }

    public function test_select_respects_per_host_overcommit_and_single_instance_physical_cap(): void
    {
        $hostA = $this->createHost('host-a-overcommit', cpu: 4, ram: 16384, disk: 500);
        $hostA->update(['cpu_overcommit' => 2.0]);

        $user = User::factory()->create();

        // Existing VM consumes 4 of the 8 overcommitted CPU cores
        VirtualMachine::create([
            'user_id' => $user->id,
            'host_id' => $hostA->id,
            'name' => 'existing-overcommit-vm',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'running',
            'cpu_cores' => 4,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu:24.04',
        ]);

        // A 4-core VM fits: committed 4 + requested 4 <= 8, and requested 4 <= physical 4
        $selectedFit = $this->selector->select([
            'os_image' => 'ubuntu:24.04',
            'cpu_cores' => 4,
            'ram_mb' => 2048,
            'disk_gb' => 20,
        ]);
        $this->assertNotNull($selectedFit);
        $this->assertSame($hostA->id, $selectedFit->id);

        // Remove existing VM so pool has all 8 overcommitted cores free, then request a 6-core VM
        VirtualMachine::where('host_id', $hostA->id)->delete();

        // A single 6-core VM exceeds the 4-core physical cap even though 6 <= 8
        $selectedTooLarge = $this->selector->select([
            'os_image' => 'ubuntu:24.04',
            'cpu_cores' => 6,
            'ram_mb' => 2048,
            'disk_gb' => 20,
        ]);
        $this->assertNull($selectedTooLarge);
    }
}
