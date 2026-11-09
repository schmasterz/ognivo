<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditContext;
use App\DTO\User\CreateUserDTO;
use App\DTO\User\UpdateUserDTO;
use App\Jobs\DeleteUserWorkloadsJob;
use App\Models\QuotaGroup;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AuditUserCoverageTest extends TestCase
{
    use AuditAssertionTrait;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        app(AuditContext::class)->setRequestId('req-user-test-5678');
    }

    public function test_user_created_audited(): void
    {
        $group = QuotaGroup::firstOrCreate(
            ['name' => 'Custom Tier Dev'],
            [
                'is_default' => false,
                'max_vms' => 5,
                'max_cpu_cores' => 10,
                'max_ram_mb' => 8192,
                'max_storage_gb' => 100,
                'max_ips' => 2,
                'max_snapshots' => 3,
            ]
        );

        $service = app(UserService::class);
        $user = $service->create(new CreateUserDTO(
            name: 'Jane Doe',
            email: 'jane.doe@example.com',
            role: 'customer',
            status: 'active',
            password: 'StrongPassword123!',
            quotaGroupId: $group->id,
        ));

        $row = $this->assertAuditRow(
            action: 'user.created',
            outcome: 'success',
            tenantUserId: $user->id,
            visibility: 'tenant',
            requestId: 'req-user-test-5678',
        );

        $context = json_decode((string) $row->context, true);
        $this->assertSame('Jane Doe', $context['pii']['name']);
        $this->assertSame('jane.doe@example.com', $context['pii']['email']);
        $this->assertSame('customer', $context['role']);
        $this->assertSame($group->id, $context['quota_group_id']);
    }

    public function test_user_updated_with_diff_and_pii_and_role_and_status_changed(): void
    {
        $groupA = QuotaGroup::firstOrCreate(
            ['name' => 'Custom Tier Starter'],
            [
                'is_default' => false,
                'max_vms' => 2,
                'max_cpu_cores' => 4,
                'max_ram_mb' => 4096,
                'max_storage_gb' => 50,
                'max_ips' => 1,
                'max_snapshots' => 2,
            ]
        );

        $groupB = QuotaGroup::firstOrCreate(
            ['name' => 'Custom Tier Pro'],
            [
                'is_default' => false,
                'max_vms' => 10,
                'max_cpu_cores' => 20,
                'max_ram_mb' => 16384,
                'max_storage_gb' => 200,
                'max_ips' => 5,
                'max_snapshots' => 5,
            ]
        );

        $admin = User::factory()->create([
            'email' => 'admin.actor@example.com',
            'status' => 'active',
        ]);
        $admin->assignRole('admin');

        $user = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'original@example.com',
            'status' => 'active',
            'quota_group_id' => $groupA->id,
        ]);
        $user->assignRole('customer');
        $user->createToken('token-1');

        $service = app(UserService::class);

        $service->update(
            $user,
            new UpdateUserDTO(
                name: 'Updated Name',
                email: 'updated@example.com',
                role: 'manager',
                status: 'suspended',
                password: 'NewPassword999!',
                quotaGroupId: $groupB->id,
                hasQuotaGroupId: true,
            ),
            $admin
        );

        // 1. user.updated (diff on auditable fields)
        $updateRow = $this->assertAuditRow(
            action: 'user.updated',
            outcome: 'success',
            tenantUserId: $user->id,
            visibility: 'tenant',
            requestId: 'req-user-test-5678',
        );

        $updateContext = json_decode((string) $updateRow->context, true);
        $this->assertSame('Original Name', $updateContext['pii']['name']['old']);
        $this->assertSame('Updated Name', $updateContext['pii']['name']['new']);
        $this->assertSame('original@example.com', $updateContext['pii']['email']['old']);
        $this->assertSame('updated@example.com', $updateContext['pii']['email']['new']);

        $this->assertSame('active', $updateContext['diff']['status']['old']);
        $this->assertSame('suspended', $updateContext['diff']['status']['new']);
        $this->assertSame('customer', $updateContext['diff']['role']['old']);
        $this->assertSame('manager', $updateContext['diff']['role']['new']);
        $this->assertSame($groupA->id, $updateContext['diff']['quota_group_id']['old']);
        $this->assertSame($groupB->id, $updateContext['diff']['quota_group_id']['new']);

        // 2. user.role.changed
        $roleRow = $this->assertAuditRow(
            action: 'user.role.changed',
            outcome: 'success',
            tenantUserId: $user->id,
            visibility: 'tenant',
            requestId: 'req-user-test-5678',
        );
        $roleContext = json_decode((string) $roleRow->context, true);
        $this->assertSame('customer', $roleContext['old_role']);
        $this->assertSame('manager', $roleContext['new_role']);

        // 3. user.status.changed
        $statusRow = $this->assertAuditRow(
            action: 'user.status.changed',
            outcome: 'success',
            tenantUserId: $user->id,
            visibility: 'tenant',
            requestId: 'req-user-test-5678',
        );
        $statusContext = json_decode((string) $statusRow->context, true);
        $this->assertSame('active', $statusContext['old_status']);
        $this->assertSame('suspended', $statusContext['new_status']);

        // 4. auth.tokens.revoked with reason
        $tokenRow = $this->assertAuditRow(
            action: 'auth.tokens.revoked',
            outcome: 'success',
            tenantUserId: $user->id,
            visibility: 'tenant',
            requestId: 'req-user-test-5678',
        );
        $tokenContext = json_decode((string) $tokenRow->context, true);
        $this->assertSame('status', $tokenContext['reason']);
    }

    public function test_user_deleted_and_quota_assigned(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin.chief@example.com',
            'status' => 'active',
        ]);
        $admin->assignRole('admin');

        $user = User::factory()->create([
            'name' => 'Target User',
            'email' => 'target.user@example.com',
            'status' => 'active',
        ]);
        $user->assignRole('customer');
        $user->createToken('active-token');

        $group = QuotaGroup::firstOrCreate(
            ['name' => 'Custom Tier Enterprise'],
            [
                'is_default' => false,
                'max_vms' => 50,
                'max_cpu_cores' => 100,
                'max_ram_mb' => 131072,
                'max_storage_gb' => 1000,
                'max_ips' => 20,
                'max_snapshots' => 10,
            ]
        );

        $service = app(UserService::class);

        // Assign quota group
        $service->assignQuotaGroup($user, $group->id);

        $quotaRow = $this->assertAuditRow(
            action: 'user.quota.assigned',
            outcome: 'success',
            tenantUserId: $user->id,
            visibility: 'tenant',
            requestId: 'req-user-test-5678',
        );
        $quotaContext = json_decode((string) $quotaRow->context, true);
        $this->assertSame($group->id, $quotaContext['quota_group_id']);
        $this->assertSame('Custom Tier Enterprise', $quotaContext['quota_group_name']);

        // Delete user
        Queue::fake([DeleteUserWorkloadsJob::class]);
        $service->delete($admin, $user);

        $delRow = $this->assertAuditRow(
            action: 'user.deleted',
            outcome: 'success',
            tenantUserId: $user->id,
            visibility: 'tenant',
            requestId: 'req-user-test-5678',
        );
        $delContext = json_decode((string) $delRow->context, true);
        $this->assertSame('Target User', $delContext['pii']['name']);
        $this->assertSame('target.user@example.com', $delContext['pii']['email']);
    }
}
