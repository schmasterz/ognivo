<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProvisionVmJob;
use App\Models\Host;
use App\Models\Image;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\PanelCertificate;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Drivers\IncusDriver;
use App\Services\Hypervisor\InstanceSpecBuilder;
use App\Services\Hypervisor\TenantProjectProvisioner;
use App\Services\PanelCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TenantProvisioningArchitecturalComplianceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private UserQuota $quota;

    private Host $host;

    private PanelCertificate $cert;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create([
            'project_name' => 'proj-tenant-test',
        ]);

        $this->quota = UserQuota::create([
            'user_id' => $this->user->id,
            'max_vms' => 5,
            'max_cpu_cores' => 4,
            'max_ram_mb' => 8192,
            'max_storage_gb' => 100,
            'max_ips' => 2,
        ]);

        $this->cert = app(PanelCertificateService::class)->generate(365);

        $this->host = Host::create([
            'name' => 'incus-node-01',
            'endpoint_url' => 'https://incus-node.local:8443',
            'driver' => 'incus',
            'server_public_key_pin' => 'sha256//'.base64_encode(str_repeat('t', 32)),
            'is_active' => true,
            'total_cpu_cores' => 64,
            'total_ram_mb' => 131072,
            'total_storage_gb' => 4000,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
            'auto_attach_images' => false,
        ]);
    }

    public function test_tenant_project_creation_sends_exact_hardened_json_payload(): void
    {
        Http::fake([
            'https://incus-node.local:8443/1.0/projects/proj-tenant-test' => Http::response([], 404),
            'https://incus-node.local:8443/1.0/projects' => Http::response(['type' => 'sync', 'status' => 'Success'], 201),
            'https://incus-node.local:8443/1.0/profiles/default?project=proj-tenant-test' => Http::response([
                'metadata' => [
                    'devices' => [
                        'root' => ['type' => 'disk', 'path' => '/'],
                        'eth0' => ['type' => 'nic'],
                    ],
                ],
            ], 200),
        ]);

        $provisioner = app(TenantProjectProvisioner::class);
        $client = $provisioner->clientForHost($this->host);

        $provisioner->ensureProject($client, $this->user->project_name, $this->quota);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://incus-node.local:8443/1.0/projects' || $request->method() !== 'POST') {
                return false;
            }

            /** @var array<string, mixed> $data */
            $data = $request->data();

            return $data['name'] === 'proj-tenant-test'
                && isset($data['config'])
                && $data['config']['restricted'] === 'true'
                && $data['config']['restricted.containers.privilege'] === 'unprivileged'
                && $data['config']['restricted.devices.nic'] === 'allow'
                && $data['config']['restricted.devices.disk'] === 'managed'
                && $data['config']['features.images'] === 'true'
                && $data['config']['features.profiles'] === 'true'
                && $data['config']['features.storage.volumes'] === 'true'
                && $data['config']['limits.instances'] === '5'
                && $data['config']['limits.cpu'] === '4'
                && $data['config']['limits.memory'] === '8192MB'
                && $data['config']['limits.disk'] === '100GB';
        });
    }

    public function test_unlimited_quota_omits_limit_keys_in_project_config(): void
    {
        $unlimitedQuota = new UserQuota([
            'max_vms' => -1,
            'max_cpu_cores' => -1,
            'max_ram_mb' => -1,
            'max_storage_gb' => -1,
        ]);

        $provisioner = app(TenantProjectProvisioner::class);
        $config = $provisioner->buildProjectConfig($unlimitedQuota);

        $this->assertSame('true', $config['restricted']);
        $this->assertSame('unprivileged', $config['restricted.containers.privilege']);
        $this->assertSame('allow', $config['restricted.devices.nic']);
        $this->assertSame('managed', $config['restricted.devices.disk']);
        $this->assertArrayNotHasKey('limits.instances', $config);
        $this->assertArrayNotHasKey('limits.cpu', $config);
        $this->assertArrayNotHasKey('limits.memory', $config);
        $this->assertArrayNotHasKey('limits.disk', $config);
    }

    public function test_default_profile_is_verified_and_repaired_when_empty(): void
    {
        Http::fake([
            'https://incus-node.local:8443/1.0/projects/proj-tenant-test*' => Http::response(['metadata' => ['name' => 'proj-tenant-test']], 200),
            'https://incus-node.local:8443/1.0/profiles/default*' => Http::sequence()
                ->push(['metadata' => ['devices' => []]], 200) // Empty profile initially
                ->push(['metadata' => ['devices' => []]], 200)
                ->push([], 200),
            'https://incus-node.local:8443/1.0/storage-pools*' => Http::response(['metadata' => ['/1.0/storage-pools/custompool']], 200),
            'https://incus-node.local:8443/1.0/networks*' => Http::response(['metadata' => ['/1.0/networks/custombr0']], 200),
        ]);

        $provisioner = app(TenantProjectProvisioner::class);
        $client = $provisioner->clientForHost($this->host);

        $provisioner->ensureProject($client, $this->user->project_name, $this->quota);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/1.0/profiles/default') || $request->method() !== 'PUT') {
                return false;
            }

            /** @var array<string, mixed> $data */
            $data = $request->data();

            return isset($data['devices']['root'])
                && $data['devices']['root']['type'] === 'disk'
                && $data['devices']['root']['pool'] === 'custompool'
                && $data['devices']['root']['path'] === '/'
                && isset($data['devices']['eth0'])
                && $data['devices']['eth0']['type'] === 'nic'
                && $data['devices']['eth0']['network'] === 'custombr0'
                && $data['devices']['eth0']['name'] === 'eth0';
        });
    }

    public function test_instance_spec_builder_creates_exact_payload_with_cloud_init_and_storage(): void
    {
        $pool = IpPool::create([
            'name' => 'Public Subnet',
            'subnet' => '192.0.2.0/24',
            'is_public' => true,
        ]);

        $ip = IpAllocation::create([
            'pool_id' => $pool->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('192.0.2.55'),
            'ip_string' => '192.0.2.55',
            'status' => 'allocated',
        ]);

        $ipv6 = IpAllocation::create([
            'pool_id' => $pool->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('2001:db8::55'),
            'ip_string' => '2001:db8::55',
            'status' => 'allocated',
        ]);

        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'workload-alpha',
            'incus_project' => $this->user->project_name,
            'instance_type' => 'virtual-machine',
            'cpu_cores' => 4,
            'ram_mb' => 4096,
            'disk_gb' => 50,
            'os_image' => 'images:ubuntu/24.04',
            'ipv4_address' => '192.0.2.55',
            'ipv6_address' => '2001:db8::55',
            'cloud_init' => [
                'user-data' => "#cloud-config\nhostname: workload-alpha\nmanage_etc_hosts: true",
            ],
        ]);

        $ip->update(['virtual_machine_id' => $vm->id]);
        $ipv6->update(['virtual_machine_id' => $vm->id]);

        $builder = app(InstanceSpecBuilder::class);
        $payload = $builder->build(
            vm: $vm,
            storagePool: 'nvme-pool',
            network: 'tenantbr0',
            allocatedIp: $ip,
            allocatedIpv6: $ipv6,
        );

        $this->assertSame('workload-alpha', $payload['name']);
        $this->assertSame('virtual-machine', $payload['type']);

        // Check source (normalized from 'images:ubuntu/24.04' to 'ubuntu/24.04/cloud' when cloud-init is present)
        $this->assertSame([
            'type' => 'image',
            'mode' => 'pull',
            'server' => 'https://images.linuxcontainers.org',
            'protocol' => 'simplestreams',
            'alias' => 'ubuntu/24.04/cloud',
        ], $payload['source']);

        // Check config
        $this->assertSame('4', $payload['config']['limits.cpu']);
        $this->assertSame('4096MB', $payload['config']['limits.memory']);
        $this->assertSame("#cloud-config\nhostname: workload-alpha\nmanage_etc_hosts: true", $payload['config']['cloud-init.user-data']);

        // Check devices.root
        $this->assertSame([
            'type' => 'disk',
            'pool' => 'nvme-pool',
            'path' => '/',
            'size' => '50GB',
        ], $payload['devices']['root']);

        // Check devices.eth0 routed interface
        $this->assertEquals([
            'type' => 'nic',
            'nictype' => 'routed',
            'parent' => 'eth0',
            'ipv4.address' => '192.0.2.55',
        ], $payload['devices']['eth0']);
    }

    public function test_driver_create_instance_sends_exact_json_payload(): void
    {
        $pool = IpPool::create([
            'name' => 'Subnet',
            'subnet' => '10.10.0.0/24',
            'is_public' => true,
        ]);

        $ip = IpAllocation::create([
            'pool_id' => $pool->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('10.10.0.8'),
            'ip_string' => '10.10.0.8',
            'status' => 'allocated',
        ]);

        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'db-instance-01',
            'incus_project' => $this->user->project_name,
            'instance_type' => 'container',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 25,
            'os_image' => 'images:debian/12',
            'ipv4_address' => '10.10.0.8',
            'cloud_init' => [
                'user-data' => '#cloud-config',
            ],
        ]);
        $ip->update(['virtual_machine_id' => $vm->id]);

        Http::fake([
            'https://incus-node.local:8443/1.0/projects/proj-tenant-test' => Http::response(['metadata' => ['name' => 'proj-tenant-test']], 200),
            'https://incus-node.local:8443/1.0/projects/proj-tenant-test*' => Http::response([], 200),
            'https://incus-node.local:8443/1.0/profiles/default?project=proj-tenant-test' => Http::response([
                'metadata' => [
                    'devices' => [
                        'root' => ['type' => 'disk', 'path' => '/'],
                        'eth0' => ['type' => 'nic'],
                    ],
                ],
            ], 200),
            'https://incus-node.local:8443/1.0/storage-pools' => Http::response(['metadata' => ['/1.0/storage-pools/default']], 200),
            'https://incus-node.local:8443/1.0/networks' => Http::response(['metadata' => ['/1.0/networks/eth0', '/1.0/networks/incusbr0']], 200),
            'https://incus-node.local:8443/1.0/instances?project=proj-tenant-test' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
            ], 201),
        ]);

        $driver = new IncusDriver($this->host);
        $result = $driver->createInstance($vm);

        $this->assertTrue($result);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/1.0/instances') || $request->method() !== 'POST') {
                return false;
            }

            /** @var array<string, mixed> $data */
            $data = $request->data();

            return $data['name'] === 'db-instance-01'
                && $data['type'] === 'container'
                && $data['devices']['root']['size'] === '25GB'
                && $data['devices']['eth0']['nictype'] === 'routed'
                && $data['devices']['eth0']['ipv4.address'] === '10.10.0.8'
                && $data['config']['limits.cpu'] === '2'
                && $data['config']['limits.memory'] === '2048MB'
                && $data['config']['cloud-init.user-data'] === '#cloud-config';
        });
    }

    public function test_quota_decrease_updates_project_limits_via_listener(): void
    {
        // Associate VM with this user on this active host
        VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'active-vm',
            'incus_project' => $this->user->project_name,
            'instance_type' => 'virtual-machine',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        Http::fake([
            'https://incus-node.local:8443/1.0/projects/proj-tenant-test' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
            ], 200),
        ]);

        // Decrease quota
        $this->quota->update([
            'max_cpu_cores' => 2,
            'max_ram_mb' => 2048,
            'max_storage_gb' => 50,
            'max_vms' => 3,
        ]);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://incus-node.local:8443/1.0/projects/proj-tenant-test' || $request->method() !== 'PUT') {
                return false;
            }

            /** @var array<string, mixed> $data */
            $data = $request->data();
            /** @var array<string, string> $config */
            $config = $data['config'] ?? [];

            return $config['limits.cpu'] === '2'
                && $config['limits.memory'] === '2048MB'
                && $config['limits.disk'] === '50GB'
                && $config['limits.instances'] === '3';
        });
    }

    public function test_image_not_in_allowlist_fails_validation(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/vms', [
                'name' => 'unapproved-image-vm',
                'cpu_cores' => 1,
                'ram_mb' => 1024,
                'disk_gb' => 10,
                'os_image' => 'malicious/unapproved-os:latest',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['os_image']);
    }

    public function test_disabled_image_in_allowlist_fails_validation(): void
    {
        Image::create([
            'name' => 'deprecated/linux:1.0',
            'source' => 'https://images.linuxcontainers.org',
            'protocol' => 'simplestreams',
            'enabled' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/vms', [
                'name' => 'disabled-image-vm',
                'cpu_cores' => 1,
                'ram_mb' => 1024,
                'disk_gb' => 10,
                'os_image' => 'deprecated/linux:1.0',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['os_image']);
    }

    public function test_enabled_image_in_allowlist_passes_validation(): void
    {
        Queue::fake([ProvisionVmJob::class]);

        $img = Image::create([
            'name' => 'custom/approved:2.0',
            'source' => 'https://images.linuxcontainers.org',
            'protocol' => 'simplestreams',
            'enabled' => true,
        ]);
        $img->hosts()->attach($this->host->id);
        IpPool::create([
            'name' => 'compliance-pool',
            'subnet' => '10.10.0.0/24',
            'host_id' => $this->host->id,
            'is_public' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/vms', [
                'name' => 'approved-image-vm',
                'cpu_cores' => 1,
                'ram_mb' => 1024,
                'disk_gb' => 10,
                'os_image' => 'custom/approved:2.0',
            ]);

        $response->assertStatus(202);
        $response->assertJsonPath('virtual_machine.name', 'approved-image-vm');
    }
}
