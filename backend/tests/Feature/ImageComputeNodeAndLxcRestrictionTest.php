<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProvisionVmJob;
use App\Models\Host;
use App\Models\Image;
use App\Models\IpPool;
use App\Models\User;
use App\Models\UserQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ImageComputeNodeAndLxcRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Host $hostA;

    private Host $hostB;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ProvisionVmJob::class]);

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $customerRole = Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole($adminRole);

        $this->customer = User::factory()->create();
        $this->customer->assignRole($customerRole);

        UserQuota::create([
            'user_id' => $this->customer->id,
            'max_vms' => 10,
            'max_cpu_cores' => 16,
            'max_ram_mb' => 32768,
            'max_storage_gb' => 500,
            'max_ips' => 10,
        ]);

        $this->hostA = Host::create([
            'name' => 'compute-node-frankfurt',
            'endpoint_url' => 'https://10.0.1.10:8443',
            'driver' => 'fake',
            'is_active' => true,
            'total_cpu_cores' => 64,
            'total_ram_mb' => 131072,
            'total_storage_gb' => 4000,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
            'auto_attach_images' => false,
        ]);

        $this->hostB = Host::create([
            'name' => 'compute-node-virginia',
            'endpoint_url' => 'https://10.0.2.10:8443',
            'driver' => 'fake',
            'is_active' => true,
            'total_cpu_cores' => 64,
            'total_ram_mb' => 131072,
            'total_storage_gb' => 4000,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
            'auto_attach_images' => false,
        ]);

        IpPool::create([
            'name' => 'Pool A',
            'cidr' => '10.1.0.0/24',
            'host_id' => $this->hostA->id,
            'is_public' => true,
        ]);

        IpPool::create([
            'name' => 'Pool B',
            'cidr' => '10.2.0.0/24',
            'host_id' => $this->hostB->id,
            'is_public' => true,
        ]);
    }

    public function test_provisioning_fails_if_image_not_associated_with_chosen_compute_node(): void
    {
        // Image only attached to hostA
        $image = Image::create([
            'name' => 'debian/dedicated-eu:12',
            'source' => 'https://images.linuxcontainers.org',
            'protocol' => 'simplestreams',
            'os_type' => 'linux',
            'enabled' => true,
        ]);
        $image->hosts()->attach([$this->hostA->id]);

        // Attempt to provision on hostB
        $response = $this->actingAs($this->customer)
            ->postJson('/api/vms', [
                'name' => 'test-foreign-host',
                'instance_type' => 'virtual-machine',
                'cpu_cores' => 2,
                'ram_mb' => 2048,
                'disk_gb' => 20,
                'os_image' => 'debian/dedicated-eu:12',
                'host_id' => $this->hostB->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['os_image']);
        $this->assertStringContainsString('not available on the chosen compute node', (string) $response->json('errors.os_image.0'));
    }

    public function test_provisioning_fails_if_container_instance_attempts_to_use_non_linux_image(): void
    {
        // Windows image attached to hostA
        $windowsImage = Image::create([
            'name' => 'windows/server-2025',
            'source' => 'https://images.custom.org',
            'protocol' => 'simplestreams',
            'os_type' => 'windows',
            'enabled' => true,
        ]);
        $windowsImage->hosts()->attach([$this->hostA->id]);

        // Attempt to provision as container
        $response = $this->actingAs($this->customer)
            ->postJson('/api/vms', [
                'name' => 'lxc-windows-test',
                'instance_type' => 'container',
                'cpu_cores' => 2,
                'ram_mb' => 2048,
                'disk_gb' => 20,
                'os_image' => 'windows/server-2025',
                'host_id' => $this->hostA->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['instance_type']);
        $this->assertStringContainsString('Containers (LXC) can only run Linux', (string) $response->json('errors.instance_type.0'));
    }

    public function test_provisioning_virtual_machine_with_windows_image_succeeds_on_associated_host(): void
    {
        $windowsImage = Image::create([
            'name' => 'windows/server-2022-vm',
            'source' => 'https://images.custom.org',
            'protocol' => 'simplestreams',
            'os_type' => 'windows',
            'enabled' => true,
        ]);
        $windowsImage->hosts()->attach([$this->hostA->id]);

        $response = $this->actingAs($this->customer)
            ->postJson('/api/vms', [
                'name' => 'vm-windows-test',
                'instance_type' => 'virtual-machine',
                'cpu_cores' => 2,
                'ram_mb' => 4096,
                'disk_gb' => 50,
                'os_image' => 'windows/server-2022-vm',
                'host_id' => $this->hostA->id,
            ]);

        $response->assertStatus(202);
        $response->assertJsonPath('virtual_machine.name', 'vm-windows-test');
    }

    public function test_client_api_images_filters_by_host_and_container_type(): void
    {
        $linuxA = Image::create(['name' => 'linux-a', 'source' => 'https://src.org', 'os_type' => 'linux', 'enabled' => true]);
        $linuxA->hosts()->attach([$this->hostA->id]);

        $linuxB = Image::create(['name' => 'linux-b', 'source' => 'https://src.org', 'os_type' => 'linux', 'enabled' => true]);
        $linuxB->hosts()->attach([$this->hostB->id]);

        $windowsA = Image::create(['name' => 'windows-a', 'source' => 'https://src.org', 'os_type' => 'windows', 'enabled' => true]);
        $windowsA->hosts()->attach([$this->hostA->id]);

        // Filter by host A only
        $resA = $this->actingAs($this->customer)->getJson('/api/images?host_id='.$this->hostA->id);
        $resA->assertStatus(200);
        $namesA = collect($resA->json('data'))->pluck('name');
        $this->assertTrue($namesA->contains('linux-a'));
        $this->assertTrue($namesA->contains('windows-a'));
        $this->assertFalse($namesA->contains('linux-b'));

        // Filter by container instance_type (no Windows)
        $resContainer = $this->actingAs($this->customer)->getJson('/api/images?host_id='.$this->hostA->id.'&instance_type=container');
        $resContainer->assertStatus(200);
        $namesContainer = collect($resContainer->json('data'))->pluck('name');
        $this->assertTrue($namesContainer->contains('linux-a'));
        $this->assertFalse($namesContainer->contains('windows-a'));
    }

    public function test_admin_can_crud_images_and_sync_node_associations(): void
    {
        // 1. Create with multiple nodes
        $createRes = $this->actingAs($this->admin)->postJson('/api/admin/images', [
            'name' => 'alpine/edge',
            'alias' => 'Test Image Alias',
            'source' => 'https://images.linuxcontainers.org',
            'protocol' => 'simplestreams',
            'os_type' => 'linux',
            'enabled' => true,
            'host_ids' => [$this->hostA->id, $this->hostB->id],
        ]);

        $createRes->assertStatus(201);
        $imageId = $createRes->json('image.id');
        $this->assertCount(2, $createRes->json('image.host_ids'));

        // 2. Update to remove hostB (X on node)
        $updateRes = $this->actingAs($this->admin)->putJson("/api/admin/images/{$imageId}", [
            'host_ids' => [$this->hostA->id],
        ]);

        $updateRes->assertStatus(200);
        $this->assertEquals([$this->hostA->id], $updateRes->json('image.host_ids'));

        // 3. Customer is forbidden from admin endpoints
        $customerRes = $this->actingAs($this->customer)->postJson('/api/admin/images', [
            'name' => 'hacked/image',
            'alias' => 'Test Image Alias',
            'source' => 'https://bad.org',
        ]);
        $customerRes->assertStatus(403);
    }

    public function test_tenant_api_images_does_not_leak_sensitive_host_node_details(): void
    {
        $image = Image::create([
            'name' => 'debian/12',
            'source' => 'https://images.linuxcontainers.org',
            'protocol' => 'simplestreams',
            'os_type' => 'linux',
            'enabled' => true,
        ]);
        $image->hosts()->attach([$this->hostA->id]);

        // Non-admin tenant
        $tenantRes = $this->actingAs($this->customer)->getJson('/api/images');
        $tenantRes->assertStatus(200);

        /** @var array<int, array<string, mixed>> $tenantHosts */
        $tenantHosts = $tenantRes->json('data.0.hosts');
        $this->assertNotEmpty($tenantHosts);

        $tenantHost = $tenantHosts[0];
        $this->assertArrayNotHasKey('endpoint_url', $tenantHost);
        $this->assertArrayNotHasKey('server_fingerprint', $tenantHost);
        $this->assertArrayNotHasKey('server_public_key_pin', $tenantHost);
        $this->assertArrayNotHasKey('total_cpu_cores', $tenantHost);
        $this->assertArrayNotHasKey('capacity', $tenantHost);

        $tenantRes->assertDontSee('endpoint_url');
        $tenantRes->assertDontSee('server_fingerprint');
        $tenantRes->assertDontSee('server_public_key_pin');
        $tenantRes->assertDontSee('total_cpu_cores');
        $tenantRes->assertDontSee('capacity');

        // Admin calling GET /api/images receives HostNodeResource fields
        $adminRes = $this->actingAs($this->admin)->getJson('/api/images');
        $adminRes->assertStatus(200);

        /** @var array<int, array<string, mixed>> $adminHosts */
        $adminHosts = $adminRes->json('data.0.hosts');
        $this->assertNotEmpty($adminHosts);

        $adminHost = $adminHosts[0];
        $this->assertArrayHasKey('endpoint_url', $adminHost);
        $this->assertArrayHasKey('server_fingerprint', $adminHost);
        $this->assertArrayHasKey('server_public_key_pin', $adminHost);
        $this->assertArrayHasKey('total_cpu_cores', $adminHost);
        $this->assertArrayHasKey('capacity', $adminHost);

        // Admin calling GET /api/admin/images receives HostNodeResource fields
        $adminApiRes = $this->actingAs($this->admin)->getJson('/api/admin/images');
        $adminApiRes->assertStatus(200);
        $adminApiRes->assertJsonStructure([
            'data',
            'next_cursor',
            'per_page',
            'counts' => [
                'filtered' => ['catalog', 'attached'],
                'overall' => ['catalog', 'attached'],
            ],
        ]);
        $this->assertArrayNotHasKey('meta', (array) $adminApiRes->json());
        $this->assertArrayNotHasKey('links', (array) $adminApiRes->json());

        /** @var array<int, array<string, mixed>> $adminApiHosts */
        $adminApiHosts = $adminApiRes->json('data.0.hosts');
        $this->assertNotEmpty($adminApiHosts);

        $adminApiHost = $adminApiHosts[0];
        $this->assertArrayHasKey('endpoint_url', $adminApiHost);
        $this->assertArrayHasKey('server_fingerprint', $adminApiHost);
        $this->assertArrayHasKey('server_public_key_pin', $adminApiHost);
        $this->assertArrayHasKey('total_cpu_cores', $adminApiHost);
        $this->assertArrayHasKey('capacity', $adminApiHost);
    }

    public function test_admin_images_cursor_pagination_across_pages(): void
    {
        Image::query()->delete();

        $imageIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $img = Image::create([
                'name' => "test-image-{$i}",
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
            ]);
            $imageIds[] = $img->id;
        }

        rsort($imageIds);

        // Page 1: per_page=2
        $res1 = $this->actingAs($this->admin)->getJson('/api/admin/images?per_page=2');
        $res1->assertStatus(200);
        $res1->assertJsonCount(2, 'data');
        $this->assertNotNull($res1->json('next_cursor'));
        $page1Ids = collect($res1->json('data'))->pluck('id')->all();
        $this->assertSame([$imageIds[0], $imageIds[1]], $page1Ids);

        $cursor1 = $res1->json('next_cursor');

        // Page 2: per_page=2&cursor=$cursor1
        $res2 = $this->actingAs($this->admin)->getJson('/api/admin/images?per_page=2&cursor='.urlencode((string) $cursor1));
        $res2->assertStatus(200);
        $res2->assertJsonCount(2, 'data');
        $this->assertNotNull($res2->json('next_cursor'));
        $page2Ids = collect($res2->json('data'))->pluck('id')->all();
        $this->assertSame([$imageIds[2], $imageIds[3]], $page2Ids);

        $cursor2 = $res2->json('next_cursor');

        // Page 3: per_page=2&cursor=$cursor2
        $res3 = $this->actingAs($this->admin)->getJson('/api/admin/images?per_page=2&cursor='.urlencode((string) $cursor2));
        $res3->assertStatus(200);
        $res3->assertJsonCount(1, 'data');
        $this->assertNull($res3->json('next_cursor'));
        $page3Ids = collect($res3->json('data'))->pluck('id')->all();
        $this->assertSame([$imageIds[4]], $page3Ids);

        $allReceived = array_merge($page1Ids, $page2Ids, $page3Ids);
        $this->assertSame($imageIds, $allReceived);
    }

    public function test_admin_images_host_id_adds_attached_flag(): void
    {
        Image::query()->delete();

        $imgAttached = Image::create([
            'name' => 'ubuntu/24.04',
            'source' => 'https://images.canonical.com',
            'protocol' => 'simplestreams',
            'os_type' => 'linux',
            'enabled' => true,
        ]);
        $imgAttached->hosts()->sync([$this->hostA->id]);

        $imgDetached = Image::create([
            'name' => 'debian/12',
            'source' => 'https://images.debian.org',
            'protocol' => 'simplestreams',
            'os_type' => 'linux',
            'enabled' => true,
        ]);
        $imgDetached->hosts()->sync([]);

        // When host_id is provided
        $responseWithHost = $this->actingAs($this->admin)->getJson('/api/admin/images?host_id='.$this->hostA->id);
        $responseWithHost->assertStatus(200);

        $items = collect($responseWithHost->json('data'))->keyBy('id');
        $this->assertTrue($items[$imgAttached->id]['attached']);
        $this->assertFalse($items[$imgDetached->id]['attached']);

        // When host_id is NOT provided, 'attached' is omitted
        $responseWithoutHost = $this->actingAs($this->admin)->getJson('/api/admin/images');
        $responseWithoutHost->assertStatus(200);

        foreach ($responseWithoutHost->json('data') as $item) {
            $this->assertArrayNotHasKey('attached', $item);
        }
    }

    public function test_admin_images_attachment_filters(): void
    {
        Image::query()->delete();

        $imgAttached = Image::create([
            'name' => 'ubuntu/24.04',
            'source' => 'https://images.canonical.com',
            'protocol' => 'simplestreams',
            'os_type' => 'linux',
            'enabled' => true,
        ]);
        $imgAttached->hosts()->sync([$this->hostA->id]);

        $imgDetached = Image::create([
            'name' => 'debian/12',
            'source' => 'https://images.debian.org',
            'protocol' => 'simplestreams',
            'os_type' => 'linux',
            'enabled' => true,
        ]);
        $imgDetached->hosts()->sync([]);

        // attachment=attached
        $resAttached = $this->actingAs($this->admin)->getJson('/api/admin/images?host_id='.$this->hostA->id.'&attachment=attached');
        $resAttached->assertStatus(200);
        $resAttached->assertJsonCount(1, 'data');
        $this->assertSame($imgAttached->id, $resAttached->json('data.0.id'));
        $this->assertTrue($resAttached->json('data.0.attached'));

        // attachment=detached
        $resDetached = $this->actingAs($this->admin)->getJson('/api/admin/images?host_id='.$this->hostA->id.'&attachment=detached');
        $resDetached->assertStatus(200);
        $resDetached->assertJsonCount(1, 'data');
        $this->assertSame($imgDetached->id, $resDetached->json('data.0.id'));
        $this->assertFalse($resDetached->json('data.0.attached'));

        // attachment=all
        $resAll = $this->actingAs($this->admin)->getJson('/api/admin/images?host_id='.$this->hostA->id.'&attachment=all');
        $resAll->assertStatus(200);
        $resAll->assertJsonCount(2, 'data');
    }

    public function test_admin_images_counts_filtered_and_overall(): void
    {
        Image::query()->delete();

        // 1: Linux, enabled, attached to hostA
        $img1 = Image::create([
            'name' => 'ubuntu/noble',
            'source' => 'https://images.canonical.com',
            'protocol' => 'simplestreams',
            'os_type' => 'linux',
            'enabled' => true,
        ]);
        $img1->hosts()->sync([$this->hostA->id]);

        // 2: Linux, enabled, detached from hostA
        $img2 = Image::create([
            'name' => 'debian/bookworm',
            'source' => 'https://images.debian.org',
            'protocol' => 'simplestreams',
            'os_type' => 'linux',
            'enabled' => true,
        ]);
        $img2->hosts()->sync([]);

        // 3: Windows, enabled, attached to hostA
        $img3 = Image::create([
            'name' => 'windows/server-2022',
            'source' => 'https://images.microsoft.com',
            'protocol' => 'simplestreams',
            'os_type' => 'windows',
            'enabled' => true,
        ]);
        $img3->hosts()->sync([$this->hostA->id]);

        // 4: Linux, disabled, detached from hostA
        $img4 = Image::create([
            'name' => 'arch/latest',
            'source' => 'https://images.archlinux.org',
            'protocol' => 'simplestreams',
            'os_type' => 'linux',
            'enabled' => false,
        ]);
        $img4->hosts()->sync([]);

        // Filter: os_type=linux & enabled=1 & host_id=hostA
        $res = $this->actingAs($this->admin)->getJson(
            '/api/admin/images?host_id='.$this->hostA->id.'&os_type=linux&enabled=1'
        );
        $res->assertStatus(200);

        // Overall: 4 catalog images, 2 attached to hostA (img1 + img3)
        $res->assertJsonPath('counts.overall.catalog', 4);
        $res->assertJsonPath('counts.overall.attached', 2);

        // Filtered (os_type=linux, enabled=true):
        // Matching catalog images = img1, img2 (2 images)
        // Of those, attached to hostA = img1 (1 image)
        $res->assertJsonPath('counts.filtered.catalog', 2);
        $res->assertJsonPath('counts.filtered.attached', 1);

        // When attachment=attached tab is selected, filtered counts must remain identical (ignoring attachment tab)
        $resTab = $this->actingAs($this->admin)->getJson(
            '/api/admin/images?host_id='.$this->hostA->id.'&os_type=linux&enabled=1&attachment=attached'
        );
        $resTab->assertStatus(200);
        $resTab->assertJsonPath('counts.filtered.catalog', 2);
        $resTab->assertJsonPath('counts.filtered.attached', 1);
        $resTab->assertJsonCount(1, 'data'); // data only has img1
    }

    public function test_admin_images_attachment_without_host_id_returns_422(): void
    {
        $res1 = $this->actingAs($this->admin)->getJson('/api/admin/images?attachment=attached');
        $res1->assertStatus(422);
        $res1->assertJsonValidationErrors(['attachment']);

        $res2 = $this->actingAs($this->admin)->getJson('/api/admin/images?attachment=detached');
        $res2->assertStatus(422);
        $res2->assertJsonValidationErrors(['attachment']);

        $res3 = $this->actingAs($this->admin)->getJson('/api/admin/images?attachment=all');
        $res3->assertStatus(422);
        $res3->assertJsonValidationErrors(['attachment']);
    }

    public function test_admin_images_invalid_cursor_returns_422(): void
    {
        // 1. Not base64
        $res1 = $this->actingAs($this->admin)->getJson('/api/admin/images?cursor=invalid!cursor');
        $res1->assertStatus(422);
        $res1->assertJsonValidationErrors(['cursor']);

        // 2. Base64 but not valid JSON
        $res2 = $this->actingAs($this->admin)->getJson('/api/admin/images?cursor='.base64_encode('not-json'));
        $res2->assertStatus(422);
        $res2->assertJsonValidationErrors(['cursor']);

        // 3. JSON without id key
        $res3 = $this->actingAs($this->admin)->getJson('/api/admin/images?cursor='.base64_encode((string) json_encode(['foo' => 'bar'])));
        $res3->assertStatus(422);
        $res3->assertJsonValidationErrors(['cursor']);

        // 4. JSON with non-positive id
        $res4 = $this->actingAs($this->admin)->getJson('/api/admin/images?cursor='.base64_encode((string) json_encode(['id' => -1])));
        $res4->assertStatus(422);
        $res4->assertJsonValidationErrors(['cursor']);
    }

    public function test_admin_images_forbidden_for_non_admin(): void
    {
        $response = $this->actingAs($this->customer)->getJson('/api/admin/images');
        $response->assertStatus(403);
    }

    public function test_admin_images_non_existent_host_id_returns_422(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/images?host_id=999999');
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['host_id']);
    }
}
