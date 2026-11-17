<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InvalidSnapshotStateException;
use App\Jobs\CreateSnapshotJob;
use App\Jobs\DeleteSnapshotJob;
use App\Jobs\RestoreSnapshotJob;
use App\Models\Host;
use App\Models\QuotaGroup;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Models\VmSnapshot;
use App\Services\VmSnapshotService;
use App\States\Vm\Deleting;
use App\States\Vm\Failed;
use App\States\Vm\Provisioning;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class VmSnapshotApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customerA;

    private User $customerB;

    private Host $host;

    private VirtualMachine $vmA;

    private VirtualMachine $vmB;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $defaultGroup = QuotaGroup::where('is_default', true)->firstOrFail();

        $this->admin = User::factory()->create([
            'name' => 'Root Admin',
            'email' => 'admin@snap-api.local',
            'status' => 'active',
        ]);
        $this->admin->assignRole('admin');
        UserQuota::create([
            'user_id' => $this->admin->id,
            'max_vms' => -1,
            'max_cpu_cores' => -1,
            'max_ram_mb' => -1,
            'max_storage_gb' => -1,
            'max_ips' => -1,
            'max_snapshots' => -1,
        ]);

        $this->customerA = User::factory()->create([
            'name' => 'Tenant Alpha',
            'email' => 'alpha@snap-api.local',
            'status' => 'active',
            'project_name' => 'tenant-alpha',
            'quota_group_id' => $defaultGroup->id,
        ]);
        $this->customerA->assignRole('customer');
        UserQuota::create([
            'user_id' => $this->customerA->id,
            'quota_group_id' => $defaultGroup->id,
            'max_vms' => 4,
            'max_cpu_cores' => 8,
            'max_ram_mb' => 8192,
            'max_storage_gb' => 100,
            'max_ips' => 2,
            'max_snapshots' => 3,
        ]);

        $this->customerB = User::factory()->create([
            'name' => 'Tenant Bravo',
            'email' => 'bravo@snap-api.local',
            'status' => 'active',
            'project_name' => 'tenant-bravo',
            'quota_group_id' => $defaultGroup->id,
        ]);
        $this->customerB->assignRole('customer');
        UserQuota::create([
            'user_id' => $this->customerB->id,
            'quota_group_id' => $defaultGroup->id,
            'max_vms' => 4,
            'max_cpu_cores' => 8,
            'max_ram_mb' => 8192,
            'max_storage_gb' => 100,
            'max_ips' => 2,
            'max_snapshots' => 3,
        ]);

        $this->host = Host::create([
            'name' => 'snap-api-node-01',
            'region' => 'eu-central-1',
            'endpoint_url' => 'https://internal-hypervisor.infra:8443',
            'server_certificate' => "-----BEGIN CERTIFICATE-----\nMIIB...secret...cert\n-----END CERTIFICATE-----",
            'server_fingerprint' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'driver' => 'fake',
            'token' => 'super-secret-trust-token',
            'status' => 'ok',
            'is_active' => true,
            'settings' => ['internal_secret' => 'do-not-leak'],
        ]);

        $this->vmA = VirtualMachine::create([
            'user_id' => $this->customerA->id,
            'host_id' => $this->host->id,
            'name' => 'alpha-web-vm',
            'incus_project' => 'tenant-alpha',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $this->vmB = VirtualMachine::create([
            'user_id' => $this->customerB->id,
            'host_id' => $this->host->id,
            'name' => 'bravo-db-vm',
            'incus_project' => 'tenant-bravo',
            'instance_type' => 'virtual-machine',
            'status' => Stopped::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:debian/12',
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_list_snapshots_returns_vm_snapshot_resources_and_quota_meta(): void
    {
        $snap1 = VmSnapshot::factory()->ready()->create([
            'virtual_machine_id' => $this->vmA->id,
            'user_id' => $this->customerA->id,
            'name' => 'snap-baseline',
            'source' => VmSnapshot::SOURCE_MANUAL,
            'stateful' => false,
            'size_bytes' => 536870912,
        ]);
        $snap2 = VmSnapshot::factory()->creating()->create([
            'virtual_machine_id' => $this->vmA->id,
            'user_id' => $this->customerA->id,
            'name' => 'snap-stateful',
            'source' => VmSnapshot::SOURCE_MANUAL,
            'stateful' => true,
            'size_bytes' => null,
        ]);

        // Another tenant's snapshot must never appear in vmA's list
        VmSnapshot::factory()->ready()->create([
            'virtual_machine_id' => $this->vmB->id,
            'user_id' => $this->customerB->id,
            'name' => 'bravo-secret-snap',
        ]);

        $response = $this->actingAs($this->customerA)
            ->getJson("/api/vms/{$this->vmA->id}/snapshots");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.used', 2)
            ->assertJsonPath('meta.max_snapshots', 3)
            ->assertJsonPath('meta.remaining', 1);

        $firstItem = $response->json('data.0');
        $this->assertSame([
            'id',
            'name',
            'source',
            'status',
            'stateful',
            'size_bytes',
            'error',
            'created_at',
        ], array_keys($firstItem));

        $names = array_column($response->json('data'), 'name');
        $this->assertContains($snap1->name, $names);
        $this->assertContains($snap2->name, $names);
        $this->assertNotContains('bravo-secret-snap', $names);

        // Admin can also view any tenant's VM snapshots
        $this->actingAs($this->admin)
            ->getJson("/api/vms/{$this->vmA->id}/snapshots")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_create_snapshot_returns_202_and_dispatches_job(): void
    {
        Queue::fake();
        Event::fake();

        $response = $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$this->vmA->id}/snapshots", [
                'name' => 'pre-release-v1',
                'stateful' => true,
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('snapshot.name', 'pre-release-v1')
            ->assertJsonPath('snapshot.source', VmSnapshot::SOURCE_MANUAL)
            ->assertJsonPath('snapshot.status', VmSnapshot::STATUS_CREATING)
            ->assertJsonPath('snapshot.stateful', true)
            ->assertJsonPath('snapshot.size_bytes', null)
            ->assertJsonPath('snapshot.error', null);

        $this->assertDatabaseHas('vm_snapshots', [
            'virtual_machine_id' => $this->vmA->id,
            'user_id' => $this->customerA->id,
            'name' => 'pre-release-v1',
            'status' => VmSnapshot::STATUS_CREATING,
            'stateful' => 1,
        ]);

        Queue::assertPushed(CreateSnapshotJob::class, fn (CreateSnapshotJob $job): bool => $job->snapshot->name === 'pre-release-v1');
    }

    public function test_restore_snapshot_returns_202_and_dispatches_job(): void
    {
        Queue::fake();
        Event::fake();

        $snapshot = VmSnapshot::factory()->ready()->create([
            'virtual_machine_id' => $this->vmA->id,
            'user_id' => $this->customerA->id,
            'name' => 'clean-state',
        ]);

        $response = $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$this->vmA->id}/snapshots/{$snapshot->id}/restore");

        $response->assertStatus(202)
            ->assertJsonPath('snapshot.id', $snapshot->id)
            ->assertJsonPath('snapshot.name', 'clean-state')
            ->assertJsonPath('snapshot.status', VmSnapshot::STATUS_RESTORING);

        $this->assertDatabaseHas('vm_snapshots', [
            'id' => $snapshot->id,
            'status' => VmSnapshot::STATUS_RESTORING,
        ]);

        Queue::assertPushed(RestoreSnapshotJob::class, fn (RestoreSnapshotJob $job): bool => $job->snapshot->is($snapshot));

        // Restoring a non-ready snapshot returns 409 Conflict
        $conflictRes = $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$this->vmA->id}/snapshots/{$snapshot->id}/restore");
        $conflictRes->assertStatus(409);
    }

    public function test_delete_snapshot_returns_202_and_dispatches_job(): void
    {
        Queue::fake();
        Event::fake();

        $snapshot = VmSnapshot::factory()->ready()->create([
            'virtual_machine_id' => $this->vmA->id,
            'user_id' => $this->customerA->id,
            'name' => 'obsolete-snap',
        ]);

        $response = $this->actingAs($this->customerA)
            ->deleteJson("/api/vms/{$this->vmA->id}/snapshots/{$snapshot->id}");

        $response->assertStatus(202)
            ->assertJsonPath('snapshot.id', $snapshot->id)
            ->assertJsonPath('snapshot.status', VmSnapshot::STATUS_DELETING);

        $this->assertDatabaseHas('vm_snapshots', [
            'id' => $snapshot->id,
            'status' => VmSnapshot::STATUS_DELETING,
        ]);

        Queue::assertPushed(DeleteSnapshotJob::class, fn (DeleteSnapshotJob $job): bool => $job->snapshot->is($snapshot));
    }

    public function test_unauthorized_access_to_another_tenants_vm_or_snapshot_is_rejected_with_403_or_404(): void
    {
        Queue::fake();

        $snapA = VmSnapshot::factory()->ready()->create([
            'virtual_machine_id' => $this->vmA->id,
            'user_id' => $this->customerA->id,
            'name' => 'alpha-private-snap',
        ]);
        $snapB = VmSnapshot::factory()->ready()->create([
            'virtual_machine_id' => $this->vmB->id,
            'user_id' => $this->customerB->id,
            'name' => 'bravo-private-snap',
        ]);

        // Unauthenticated requests get 401
        $this->getJson("/api/vms/{$this->vmA->id}/snapshots")->assertStatus(401);
        $this->postJson("/api/vms/{$this->vmA->id}/snapshots", ['name' => 'unauth'])->assertStatus(401);
        $this->postJson("/api/vms/{$this->vmA->id}/snapshots/{$snapA->id}/restore")->assertStatus(401);
        $this->deleteJson("/api/vms/{$this->vmA->id}/snapshots/{$snapA->id}")->assertStatus(401);

        // 1. Customer A cannot list, create, restore, or delete on Customer B's VM -> 403 Forbidden
        $this->actingAs($this->customerA)
            ->getJson("/api/vms/{$this->vmB->id}/snapshots")
            ->assertStatus(403);

        $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$this->vmB->id}/snapshots", ['name' => 'intruder-snap'])
            ->assertStatus(403);

        // Even with an invalid name or duplicate name on Customer B's VM, Customer A gets 403 (never 422)
        $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$this->vmB->id}/snapshots", ['name' => 'bravo-private-snap'])
            ->assertStatus(403);
        $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$this->vmB->id}/snapshots", ['name' => 'INVALID NAME!'])
            ->assertStatus(403);

        $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$this->vmB->id}/snapshots/{$snapB->id}/restore")
            ->assertStatus(403);

        $this->actingAs($this->customerA)
            ->deleteJson("/api/vms/{$this->vmB->id}/snapshots/{$snapB->id}")
            ->assertStatus(403);

        // 2. Customer A cannot target Customer B's snapshot via Customer A's own VM URL -> 404 Not Found
        $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$this->vmA->id}/snapshots/{$snapB->id}/restore")
            ->assertStatus(404);

        $this->actingAs($this->customerA)
            ->deleteJson("/api/vms/{$this->vmA->id}/snapshots/{$snapB->id}")
            ->assertStatus(404);

        // 3. Snapshot belonging to a different VM owned by the same user also returns 404
        $vmA2 = VirtualMachine::create([
            'user_id' => $this->customerA->id,
            'host_id' => $this->host->id,
            'name' => 'alpha-second-vm',
            'incus_project' => 'tenant-alpha',
            'instance_type' => 'virtual-machine',
            'status' => Stopped::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$vmA2->id}/snapshots/{$snapA->id}/restore")
            ->assertStatus(404);

        $this->actingAs($this->customerA)
            ->deleteJson("/api/vms/{$vmA2->id}/snapshots/{$snapA->id}")
            ->assertStatus(404);

        // Verify snapB and snapA remain completely untouched
        $this->assertSame(VmSnapshot::STATUS_READY, $snapB->fresh()?->status);
        $this->assertSame(VmSnapshot::STATUS_READY, $snapA->fresh()?->status);
        Queue::assertNotPushed(CreateSnapshotJob::class);
        Queue::assertNotPushed(RestoreSnapshotJob::class);
        Queue::assertNotPushed(DeleteSnapshotJob::class);
    }

    public function test_quota_exceeded_returns_422_and_respects_failed_and_unlimited_quotas(): void
    {
        Queue::fake();

        // Customer A has max_snapshots = 3
        VmSnapshot::factory()->ready()->create([
            'virtual_machine_id' => $this->vmA->id,
            'user_id' => $this->customerA->id,
            'name' => 'snap-1',
        ]);
        VmSnapshot::factory()->creating()->create([
            'virtual_machine_id' => $this->vmA->id,
            'user_id' => $this->customerA->id,
            'name' => 'snap-2',
        ]);
        VmSnapshot::factory()->ready()->create([
            'virtual_machine_id' => $this->vmA->id,
            'user_id' => $this->customerA->id,
            'name' => 'snap-3',
        ]);
        // Failed snapshot does not count toward active limit
        VmSnapshot::factory()->failed()->create([
            'virtual_machine_id' => $this->vmA->id,
            'user_id' => $this->customerA->id,
            'name' => 'snap-failed',
        ]);

        // 4th active snapshot must be rejected with 422
        $exceededRes = $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$this->vmA->id}/snapshots", [
                'name' => 'snap-4',
            ]);

        $exceededRes->assertStatus(422)
            ->assertJsonValidationErrors(['quota']);

        $this->assertDatabaseMissing('vm_snapshots', [
            'virtual_machine_id' => $this->vmA->id,
            'name' => 'snap-4',
        ]);
        Queue::assertNotPushed(CreateSnapshotJob::class);

        // Updating quota to unlimited (-1) allows creating snap-4
        $this->customerA->quota()->update(['max_snapshots' => -1]);

        $unlimitedRes = $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$this->vmA->id}/snapshots", [
                'name' => 'snap-4',
            ]);

        $unlimitedRes->assertStatus(202)
            ->assertJsonPath('snapshot.name', 'snap-4');
    }

    public function test_invalid_and_duplicate_snapshot_names_are_rejected_with_422(): void
    {
        Queue::fake();

        $invalidNames = [
            '',
            'Invalid Name!',
            'UPPERCASE-SNAP',
            'snap/with/slash',
            '-leading-hyphen',
            'trailing-hyphen-',
            '../traversal',
            str_repeat('a', 64),
        ];

        foreach ($invalidNames as $badName) {
            $res = $this->actingAs($this->customerA)
                ->postJson("/api/vms/{$this->vmA->id}/snapshots", [
                    'name' => $badName,
                ]);

            $res->assertStatus(422)
                ->assertJsonValidationErrors(['name']);
        }

        // Create a valid snapshot (including underscores) and a max-length 63-char snapshot
        $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$this->vmA->id}/snapshots", [
                'name' => 'valid-snap_01',
            ])
            ->assertStatus(202);

        // Duplicate name on the same VM must fail with 422
        $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$this->vmA->id}/snapshots", [
                'name' => 'valid-snap_01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // Same name (and max-length 63-char name) on a different VM is allowed
        $this->actingAs($this->customerB)
            ->postJson("/api/vms/{$this->vmB->id}/snapshots", [
                'name' => 'valid-snap_01',
            ])
            ->assertStatus(202);

        $this->actingAs($this->customerB)
            ->postJson("/api/vms/{$this->vmB->id}/snapshots", [
                'name' => str_repeat('a', 63),
            ])
            ->assertStatus(202);
    }

    public function test_user_cannot_create_stateful_snapshot_on_container(): void
    {
        Queue::fake();

        $container = VirtualMachine::create([
            'user_id' => $this->customerA->id,
            'host_id' => $this->host->id,
            'name' => 'alpha-container-app',
            'incus_project' => 'tenant-alpha',
            'instance_type' => 'container',
            'status' => 'running',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        $response = $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$container->id}/snapshots", [
                'name' => 'cont-mem-snap',
                'stateful' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['stateful']);

        $this->assertDatabaseMissing('vm_snapshots', [
            'virtual_machine_id' => $container->id,
            'name' => 'cont-mem-snap',
        ]);
        Queue::assertNotPushed(CreateSnapshotJob::class);
    }

    public function test_snapshot_service_guards_against_stateful_snapshot_on_container(): void
    {
        $container = VirtualMachine::create([
            'user_id' => $this->customerA->id,
            'host_id' => $this->host->id,
            'name' => 'alpha-guard-container',
            'incus_project' => 'tenant-alpha',
            'instance_type' => 'container',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        $this->expectException(InvalidSnapshotStateException::class);
        $this->expectExceptionMessage('Cannot create stateful snapshot for container "alpha-guard-container". In-memory snapshots are only supported for virtual machines.');

        app(VmSnapshotService::class)->create($container, 'container-stateful', true);
    }

    public function test_sync_from_hypervisor_preserves_failed_snapshots(): void
    {
        $failedSnap = VmSnapshot::factory()->failed()->create([
            'virtual_machine_id' => $this->vmA->id,
            'user_id' => $this->customerA->id,
            'name' => 'failed-sync-preserved',
        ]);

        app(VmSnapshotService::class)->syncFromHypervisor($this->vmA);

        $this->assertDatabaseHas('vm_snapshots', [
            'id' => $failedSnap->id,
            'status' => VmSnapshot::STATUS_FAILED,
            'name' => 'failed-sync-preserved',
        ]);
    }

    public function test_invalid_vm_state_returns_409_conflict_for_create_and_restore(): void
    {
        Queue::fake();

        foreach ([Provisioning::class, Deleting::class, Failed::class] as $badState) {
            $this->vmA->update(['status' => $badState]);

            $this->actingAs($this->customerA)
                ->postJson("/api/vms/{$this->vmA->id}/snapshots", [
                    'name' => 'snap-bad-state',
                ])
                ->assertStatus(409);

            $readySnap = VmSnapshot::factory()->ready()->create([
                'virtual_machine_id' => $this->vmA->id,
                'user_id' => $this->customerA->id,
                'name' => 'snap-ready-'.strtolower(class_basename($badState)),
            ]);

            $this->actingAs($this->customerA)
                ->postJson("/api/vms/{$this->vmA->id}/snapshots/{$readySnap->id}/restore")
                ->assertStatus(409);
        }

        Queue::assertNotPushed(CreateSnapshotJob::class);
        Queue::assertNotPushed(RestoreSnapshotJob::class);
    }

    public function test_quota_endpoint_includes_snapshot_usage_limit_and_available_headroom(): void
    {
        VmSnapshot::factory()->ready()->create([
            'virtual_machine_id' => $this->vmA->id,
            'user_id' => $this->customerA->id,
            'name' => 'q-snap-1',
        ]);
        VmSnapshot::factory()->creating()->create([
            'virtual_machine_id' => $this->vmA->id,
            'user_id' => $this->customerA->id,
            'name' => 'q-snap-2',
        ]);
        VmSnapshot::factory()->failed()->create([
            'virtual_machine_id' => $this->vmA->id,
            'user_id' => $this->customerA->id,
            'name' => 'q-snap-failed',
        ]);

        $response = $this->actingAs($this->customerA)->getJson('/api/quota');

        $response->assertOk()
            ->assertJsonPath('quota.max_snapshots', 3)
            ->assertJsonPath('usage.snapshots', 2)
            ->assertJsonPath('available.snapshots', 1);

        // Unlimited snapshots (-1) reports -1 available
        $adminQuotaRes = $this->actingAs($this->admin)->getJson('/api/quota');
        $adminQuotaRes->assertOk()
            ->assertJsonPath('quota.max_snapshots', -1)
            ->assertJsonPath('available.snapshots', -1);
    }

    public function test_mutating_snapshot_endpoints_enforce_rate_limiting_and_exempt_admins(): void
    {
        Queue::fake();

        $this->customerA->quota()->update(['max_snapshots' => -1]);
        $this->customerB->quota()->update(['max_snapshots' => -1]);

        Sanctum::actingAs($this->customerA);

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson("/api/vms/{$this->vmA->id}/snapshots", [
                'name' => "rate-snap-{$i}",
            ])->assertStatus(202);
        }

        // 11th mutating request is throttled with 429
        $throttledRes = $this->postJson("/api/vms/{$this->vmA->id}/snapshots", [
            'name' => 'rate-snap-11',
        ]);

        $throttledRes->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['message', 'retry_after']);

        // Admin is exempt from rate limiting
        Sanctum::actingAs($this->admin);
        for ($i = 1; $i <= 12; $i++) {
            $this->postJson("/api/vms/{$this->vmB->id}/snapshots", [
                'name' => "admin-rate-snap-{$i}",
            ])->assertStatus(202);
        }
    }

    public function test_snapshot_responses_never_expose_internal_hypervisor_details(): void
    {
        Queue::fake();

        $failedSnap = VmSnapshot::factory()->failed()->create([
            'virtual_machine_id' => $this->vmA->id,
            'user_id' => $this->customerA->id,
            'name' => 'failed-snap',
            'error' => 'Failed connecting to https://internal-hypervisor.infra:8443/1.0/instances',
        ]);

        $listRes = $this->actingAs($this->customerA)
            ->getJson("/api/vms/{$this->vmA->id}/snapshots");
        $listRes->assertOk();

        $createRes = $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$this->vmA->id}/snapshots", ['name' => 'sec-check-snap']);
        $createRes->assertStatus(202);

        foreach ([$listRes->getContent(), $createRes->getContent()] as $rawJson) {
            $this->assertIsString($rawJson);
            $this->assertStringNotContainsString('internal-hypervisor.infra', $rawJson);
            $this->assertStringNotContainsString('super-secret-trust-token', $rawJson);
            $this->assertStringNotContainsString('BEGIN CERTIFICATE', $rawJson);
            $this->assertStringNotContainsString('do-not-leak', $rawJson);
            $this->assertStringNotContainsString('tenant-alpha', $rawJson);
        }

        $failedItem = collect($listRes->json('data'))->firstWhere('id', $failedSnap->id);
        $this->assertNotNull($failedItem);
        $this->assertStringNotContainsString('https://internal-hypervisor.infra:8443', (string) $failedItem['error']);
    }

    public function test_openapi_documentation_includes_snapshot_endpoints(): void
    {
        $docRes = $this->getJson('/docs/api.json');
        $docRes->assertOk();

        $paths = array_keys((array) $docRes->json('paths'));
        $this->assertContains('/vms/{vm}/snapshots', $paths);
        $this->assertContains('/vms/{vm}/snapshots/{snapshot}/restore', $paths);
        $this->assertContains('/vms/{vm}/snapshots/{snapshot}', $paths);
    }

    public function test_restore_of_non_ready_snapshot_states_is_strictly_rejected_with_409(): void
    {
        Queue::fake();

        $nonReadyStatuses = [
            VmSnapshot::STATUS_CREATING,
            VmSnapshot::STATUS_RESTORING,
            VmSnapshot::STATUS_DELETING,
            VmSnapshot::STATUS_FAILED,
        ];

        foreach ($nonReadyStatuses as $status) {
            $snap = VmSnapshot::factory()->create([
                'virtual_machine_id' => $this->vmA->id,
                'user_id' => $this->customerA->id,
                'name' => "snap-non-ready-{$status}",
                'status' => $status,
            ]);

            $response = $this->actingAs($this->customerA)
                ->postJson("/api/vms/{$this->vmA->id}/snapshots/{$snap->id}/restore");

            $response->assertStatus(409)
                ->assertJsonPath('message', fn (string $msg): bool => str_contains($msg, $status));
        }

        Queue::assertNotPushed(RestoreSnapshotJob::class);
    }

    public function test_simultaneous_concurrent_operations_on_one_vm_are_rejected_with_409(): void
    {
        Queue::fake();

        // 1. Snapshot A is currently restoring: creating a new snapshot must fail with 409
        $snapA = VmSnapshot::factory()->create([
            'virtual_machine_id' => $this->vmA->id,
            'user_id' => $this->customerA->id,
            'name' => 'snap-currently-restoring',
            'status' => VmSnapshot::STATUS_RESTORING,
        ]);

        $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$this->vmA->id}/snapshots", [
                'name' => 'snap-concurrent-create',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $msg): bool => str_contains(strtolower($msg), 'restoring'));

        // 2. Snapshot B is ready, but snapshot A is restoring: restoring snapshot B must fail with 409
        $snapB = VmSnapshot::factory()->ready()->create([
            'virtual_machine_id' => $this->vmA->id,
            'user_id' => $this->customerA->id,
            'name' => 'snap-ready-b',
        ]);

        $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$this->vmA->id}/snapshots/{$snapB->id}/restore")
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $msg): bool => str_contains(strtolower($msg), 'another snapshot operation'));

        // 3. Snapshot A is in creating status: restoring snapshot B must fail with 409
        $snapA->update(['status' => VmSnapshot::STATUS_CREATING]);

        $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$this->vmA->id}/snapshots/{$snapB->id}/restore")
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $msg): bool => str_contains(strtolower($msg), 'another snapshot operation'));

        // 4. Snapshot A is in deleting status: restoring snapshot B must fail with 409
        $snapA->update(['status' => VmSnapshot::STATUS_DELETING]);

        $this->actingAs($this->customerA)
            ->postJson("/api/vms/{$this->vmA->id}/snapshots/{$snapB->id}/restore")
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $msg): bool => str_contains(strtolower($msg), 'another snapshot operation'));

        // 5. Deleting a snapshot that is currently restoring must fail with 409
        $snapA->update(['status' => VmSnapshot::STATUS_RESTORING]);

        $this->actingAs($this->customerA)
            ->deleteJson("/api/vms/{$this->vmA->id}/snapshots/{$snapA->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $msg): bool => str_contains(strtolower($msg), 'currently restoring'));

        Queue::assertNotPushed(CreateSnapshotJob::class);
        Queue::assertNotPushed(RestoreSnapshotJob::class);
        Queue::assertNotPushed(DeleteSnapshotJob::class);
    }

    public function test_admin_can_manage_snapshots_on_any_tenant_vm(): void
    {
        Queue::fake();

        // 1. Admin lists tenant snapshots
        $snap = VmSnapshot::factory()->ready()->create([
            'virtual_machine_id' => $this->vmA->id,
            'user_id' => $this->customerA->id,
            'name' => 'admin-managed-snap',
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/vms/{$this->vmA->id}/snapshots")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // 2. Admin creates snapshot on tenant VM
        $this->actingAs($this->admin)
            ->postJson("/api/vms/{$this->vmA->id}/snapshots", ['name' => 'admin-taken-snap'])
            ->assertStatus(202)
            ->assertJsonPath('snapshot.name', 'admin-taken-snap');

        // Reset created snapshot to ready so it does not block subsequent restore operations
        VmSnapshot::where('virtual_machine_id', $this->vmA->id)->where('name', 'admin-taken-snap')->update(['status' => VmSnapshot::STATUS_READY]);

        // 3. Admin restores snapshot on tenant VM
        $this->actingAs($this->admin)
            ->postJson("/api/vms/{$this->vmA->id}/snapshots/{$snap->id}/restore")
            ->assertStatus(202);

        // Reset status to ready so admin can delete
        VmSnapshot::where('id', $snap->id)->update(['status' => VmSnapshot::STATUS_READY]);

        // 4. Admin deletes snapshot on tenant VM
        $this->actingAs($this->admin)
            ->deleteJson("/api/vms/{$this->vmA->id}/snapshots/{$snap->id}")
            ->assertStatus(202);

        Queue::assertPushed(CreateSnapshotJob::class);
        Queue::assertPushed(RestoreSnapshotJob::class);
        Queue::assertPushed(DeleteSnapshotJob::class);
    }
}
