<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\QuotaGroup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Models\VmSnapshot;
use App\Services\UserQuotaService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class VmSnapshotModelAndQuotaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private QuotaGroup $defaultGroup;

    private UserQuotaService $quotaService;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->defaultGroup = QuotaGroup::where('is_default', true)->firstOrFail();

        $this->admin = User::factory()->create([
            'name' => 'Root Admin',
            'email' => 'admin@snapshot-test.local',
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

        $this->customer = User::factory()->create([
            'name' => 'Snapshot Customer',
            'email' => 'customer@snapshot-test.local',
            'quota_group_id' => $this->defaultGroup->id,
        ]);
        $this->customer->assignRole('customer');
        UserQuota::create([
            'user_id' => $this->customer->id,
            'quota_group_id' => $this->defaultGroup->id,
            'max_vms' => 2,
            'max_cpu_cores' => 4,
            'max_ram_mb' => 4096,
            'max_storage_gb' => 80,
            'max_ips' => 1,
            'max_snapshots' => 3,
        ]);

        $this->quotaService = app(UserQuotaService::class);
    }

    public function test_vm_snapshot_model_relations_casts_and_factory(): void
    {
        $vm = VirtualMachine::factory()->create([
            'user_id' => $this->customer->id,
            'name' => 'vm-snap-model',
        ]);

        $snapshot = VmSnapshot::factory()->create([
            'virtual_machine_id' => $vm->id,
            'name' => 'snap-initial',
            'source' => VmSnapshot::SOURCE_MANUAL,
            'status' => VmSnapshot::STATUS_READY,
            'size_bytes' => 1073741824,
            'error' => null,
        ]);

        $this->assertSame($vm->id, $snapshot->virtual_machine_id);
        $this->assertSame($this->customer->id, $snapshot->user_id);
        $this->assertSame(1073741824, $snapshot->size_bytes);
        $this->assertNull($snapshot->error);

        // Relations
        $this->assertTrue($snapshot->virtualMachine->is($vm));
        $this->assertTrue($snapshot->user->is($this->customer));
        $this->assertCount(1, $vm->snapshots);
        $this->assertTrue($vm->snapshots->first()->is($snapshot));
        $this->assertCount(1, $this->customer->snapshots);
    }

    public function test_vm_snapshot_unique_name_per_vm_constraint(): void
    {
        $vm1 = VirtualMachine::factory()->create(['user_id' => $this->customer->id, 'name' => 'vm-u1']);
        $vm2 = VirtualMachine::factory()->create(['user_id' => $this->customer->id, 'name' => 'vm-u2']);

        VmSnapshot::factory()->create([
            'virtual_machine_id' => $vm1->id,
            'user_id' => $this->customer->id,
            'name' => 'daily-backup',
        ]);

        // Same snapshot name on a different VM is allowed
        $otherVmSnap = VmSnapshot::factory()->create([
            'virtual_machine_id' => $vm2->id,
            'user_id' => $this->customer->id,
            'name' => 'daily-backup',
        ]);
        $this->assertSame('daily-backup', $otherVmSnap->name);

        // Duplicate snapshot name on the same VM must fail
        $this->expectException(QueryException::class);
        VmSnapshot::factory()->create([
            'virtual_machine_id' => $vm1->id,
            'user_id' => $this->customer->id,
            'name' => 'daily-backup',
        ]);
    }

    public function test_deleting_vm_or_user_cascades_and_removes_snapshot_rows(): void
    {
        $vm1 = VirtualMachine::factory()->create(['user_id' => $this->customer->id, 'name' => 'vm-del-1']);
        $vm2 = VirtualMachine::factory()->create(['user_id' => $this->customer->id, 'name' => 'vm-del-2']);

        $snap1 = VmSnapshot::factory()->create([
            'virtual_machine_id' => $vm1->id,
            'user_id' => $this->customer->id,
            'name' => 'snap-1',
        ]);
        $snap2 = VmSnapshot::factory()->create([
            'virtual_machine_id' => $vm2->id,
            'user_id' => $this->customer->id,
            'name' => 'snap-2',
        ]);

        // Deleting VM 1 removes its snapshot rows
        $vm1->delete();
        $this->assertDatabaseMissing('vm_snapshots', ['id' => $snap1->id]);
        $this->assertDatabaseHas('vm_snapshots', ['id' => $snap2->id]);

        // Deleting the user removes remaining snapshot rows
        $this->customer->delete();
        $this->assertDatabaseMissing('vm_snapshots', ['id' => $snap2->id]);
    }

    public function test_seeded_standard_tier_and_admin_get_sensible_max_snapshots_values(): void
    {
        // Standard tier seeded by migration
        $this->assertSame(3, $this->defaultGroup->max_snapshots);
        $this->assertSame(3, SystemSetting::getDefaultQuota()['max_snapshots']);

        // Run DatabaseSeeder and check seeded admin and customer
        $this->seed(DatabaseSeeder::class);

        $seededAdmin = User::where('email', 'admin@ognivo.local')->firstOrFail();
        $this->assertNotNull($seededAdmin->quota);
        $this->assertSame(-1, $seededAdmin->quota->max_snapshots);
        $this->assertTrue($this->quotaService->isUnlimitedSnapshots($seededAdmin->quota->max_snapshots));

        $seededCustomer = User::where('email', 'customer@ognivo.local')->firstOrFail();
        $this->assertNotNull($seededCustomer->quota);
        $this->assertSame(3, $seededCustomer->quota->max_snapshots);

        // Also check admin:create artisan command
        $this->artisan('admin:create', [
            'email' => 'cli-admin@snapshot-test.local',
            '--password' => 'SecretPass123!',
        ])->assertExitCode(0);

        $cliAdmin = User::where('email', 'cli-admin@snapshot-test.local')->firstOrFail();
        $this->assertSame(-1, $cliAdmin->quota?->max_snapshots);
    }

    public function test_quota_group_sync_resources_and_validation_include_max_snapshots(): void
    {
        // 1. Create quota group with max_snapshots
        $createRes = $this->actingAs($this->admin)
            ->postJson('/api/admin/quota-groups', [
                'name' => 'Snapshot Pro Tier',
                'description' => 'Tier with 15 snapshots',
                'is_default' => false,
                'max_vms' => 6,
                'max_cpu_cores' => 12,
                'max_ram_mb' => 16384,
                'max_storage_gb' => 200,
                'max_ips' => 4,
                'max_snapshots' => 15,
            ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('quota_group.max_snapshots', 15);

        $groupId = (int) $createRes->json('quota_group.id');

        // 2. Assign customer to the new group and verify sync
        $assignRes = $this->actingAs($this->admin)
            ->postJson(sprintf('/api/admin/users/%d/assign-quota-group', $this->customer->id), [
                'quota_group_id' => $groupId,
            ]);
        $assignRes->assertOk();

        $this->customer->refresh();
        $this->assertSame(15, $this->customer->quota?->max_snapshots);

        // 3. Update quota group max_snapshots to infinite (-1) and verify sync to assigned users
        $updateRes = $this->actingAs($this->admin)
            ->putJson(sprintf('/api/admin/quota-groups/%d', $groupId), [
                'name' => 'Snapshot Pro Tier Unlimited',
                'is_default' => false,
                'max_vms' => 6,
                'max_cpu_cores' => 12,
                'max_ram_mb' => 16384,
                'max_storage_gb' => 200,
                'max_ips' => 4,
                'max_snapshots' => -1,
            ]);

        $updateRes->assertOk()
            ->assertJsonPath('quota_group.max_snapshots', -1);

        $this->customer->quota->refresh();
        $this->assertSame(-1, $this->customer->quota->max_snapshots);

        // 4. Validation rejects invalid max_snapshots (< -1)
        $invalidRes = $this->actingAs($this->admin)
            ->putJson(sprintf('/api/admin/quota-groups/%d', $groupId), [
                'name' => 'Invalid Snapshots Group',
                'max_vms' => 6,
                'max_cpu_cores' => 12,
                'max_ram_mb' => 16384,
                'max_storage_gb' => 200,
                'max_ips' => 4,
                'max_snapshots' => -2,
            ]);
        $invalidRes->assertStatus(422)
            ->assertJsonValidationErrors(['max_snapshots']);
    }

    public function test_can_create_snapshot_enforces_limit_and_handles_failed_and_infinite_quotas(): void
    {
        $vm1 = VirtualMachine::factory()->create(['user_id' => $this->customer->id, 'name' => 'vm-quota-1']);
        $vm2 = VirtualMachine::factory()->create(['user_id' => $this->customer->id, 'name' => 'vm-quota-2']);

        // Customer has max_snapshots = 3
        $this->assertTrue($this->quotaService->canCreateSnapshot($this->customer, $vm1));
        $this->assertSame(3, $this->quotaService->remainingSnapshots($this->customer, $vm1));

        // Create 2 active snapshots (1 ready, 1 creating) and 1 failed snapshot
        VmSnapshot::factory()->ready()->create([
            'virtual_machine_id' => $vm1->id,
            'user_id' => $this->customer->id,
            'name' => 'snap-1',
        ]);
        VmSnapshot::factory()->creating()->create([
            'virtual_machine_id' => $vm1->id,
            'user_id' => $this->customer->id,
            'name' => 'snap-2',
        ]);
        VmSnapshot::factory()->failed()->create([
            'virtual_machine_id' => $vm1->id,
            'user_id' => $this->customer->id,
            'name' => 'snap-failed',
        ]);

        // Failed snapshot does not count; 2 of 3 used -> still allowed
        $this->assertTrue($this->quotaService->canCreateSnapshot($this->customer, $vm1));
        $this->assertTrue($this->quotaService->canCreateSnapshot($vm1));
        $this->assertSame(1, $this->quotaService->remainingSnapshots($this->customer, $vm1));

        // Create 3rd active snapshot (restoring)
        VmSnapshot::factory()->restoring()->create([
            'virtual_machine_id' => $vm1->id,
            'user_id' => $this->customer->id,
            'name' => 'snap-3',
        ]);

        // Now at 3 of 3 on vm1 -> cannot create another snapshot on vm1
        $this->assertFalse($this->quotaService->canCreateSnapshot($this->customer, $vm1));
        $this->assertFalse($this->quotaService->canCreateSnapshot($vm1));
        $this->assertSame(0, $this->quotaService->remainingSnapshots($this->customer, $vm1));

        // vm2 has 0 snapshots -> can still create snapshots on vm2
        $this->assertTrue($this->quotaService->canCreateSnapshot($this->customer, $vm2));
        $this->assertSame(3, $this->quotaService->remainingSnapshots($this->customer, $vm2));

        // Tenant-wide: 3 used on vm1 across 2 active VMs (limit 6) -> 3 remaining
        $this->assertTrue($this->quotaService->canCreateSnapshot($this->customer));
        $this->assertSame(3, $this->quotaService->remainingSnapshots($this->customer));

        // Quota metrics reflect per-VM and tenant-wide snapshot availability
        $this->assertSame(3, $this->quotaService->getMetrics($this->customer)['available']['snapshots']);
        $this->assertSame(0, $this->quotaService->getMetrics($this->customer, ['vm_id' => $vm1->id])['available']['snapshots']);
        $this->assertSame(3, $this->quotaService->getMetrics($this->customer, ['vm_id' => $vm2->id])['available']['snapshots']);

        // Infinite quota semantics: -1, 0, and null all allow unlimited snapshots
        foreach ([-1, 0, null] as $unlimitedValue) {
            $this->customer->quota()->update(['max_snapshots' => $unlimitedValue]);
            $this->customer->unsetRelation('quota');

            $this->assertTrue(
                $this->quotaService->canCreateSnapshot($this->customer, $vm1),
                sprintf('Expected max_snapshots=%s to allow unlimited snapshots', var_export($unlimitedValue, true))
            );
            $this->assertSame(-1, $this->quotaService->remainingSnapshots($this->customer, $vm1));
        }

        // Reset to finite limit 3 and verify assertCanCreateSnapshot throws ValidationException
        $this->customer->quota()->update(['max_snapshots' => 3]);
        $this->expectException(ValidationException::class);
        $this->quotaService->assertCanCreateSnapshot($this->customer, $vm1);
    }
}
