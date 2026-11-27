<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditAction;
use App\Audit\AuditOutcome;
use App\Enums\IpAllocationStatus;
use App\Http\Requests\Vm\CreateVmRequest;
use App\Models\AuditLog;
use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\ServiceToken;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Models\VpsPlan;
use App\Models\VpsPlanVersion;
use App\Services\Hypervisor\InstanceSpecBuilder;
use App\States\Vm\Deleted;
use App\States\Vm\Failed;
use App\States\Vm\Running;
use App\States\VpsPlan\Draft as PlanDraft;
use App\States\VpsPlan\Published as PlanPublished;
use App\States\VpsPlanVersion\Draft as VersionDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class VirtualMachineProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $admin;

    private IpPool $pool;

    private Host $host;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->assignRole('customer');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        UserQuota::create([
            'user_id' => $this->user->id,
            'max_vms' => 2,
            'max_cpu_cores' => 4,
            'max_ram_mb' => 4096,
            'max_storage_gb' => 80,
            'max_ips' => 2,
        ]);

        UserQuota::create([
            'user_id' => $this->admin->id,
            'max_vms' => -1,
            'max_cpu_cores' => -1,
            'max_ram_mb' => -1,
            'max_storage_gb' => -1,
            'max_ips' => -1,
        ]);

        RateLimiter::clear('vm-actions:'.$this->user->id);
        RateLimiter::clear('vm-actions:'.$this->admin->id);

        $this->host = Host::create([
            'name' => 'test-node-1',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
            'total_cpu_cores' => 64,
            'total_ram_mb' => 131072,
            'total_storage_gb' => 4000,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);

        $this->pool = IpPool::create([
            'name' => 'Test Pool',
            'subnet' => '10.0.0.0/24',
            'is_public' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $versionOverrides
     * @param  array<string, mixed>  $planOverrides
     * @return array{plan: VpsPlan, version: VpsPlanVersion}
     */
    private function createPublishedPlan(array $versionOverrides = [], array $planOverrides = []): array
    {
        /** @var VpsPlan $plan */
        $plan = VpsPlan::factory()->published()->create($planOverrides);

        /** @var VpsPlanVersion $version */
        $version = VpsPlanVersion::factory()->published()->create(array_merge([
            'vps_plan_id' => $plan->id,
            'version' => 1,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 40,
            'instance_type' => 'virtual-machine',
            'net_download_mbps' => null,
            'net_upload_mbps' => null,
        ], $versionOverrides));

        $plan->forceFill(['current_version_id' => $version->id])->save();

        return ['plan' => $plan->fresh(), 'version' => $version];
    }

    public function test_user_can_provision_virtual_machine_within_quota(): void
    {
        ['plan' => $plan, 'version' => $version] = $this->createPublishedPlan([
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 40,
            'net_download_mbps' => 500,
            'net_upload_mbps' => 250,
        ], [
            'name' => 'Starter Plan',
            'slug' => 'starter-plan',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/vms', [
                'name' => 'web-server-01',
                'vps_plan_id' => $plan->id,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $response->assertStatus(202);
        $response->assertJsonPath('virtual_machine.name', 'web-server-01');
        $response->assertJsonPath('virtual_machine.cpu_cores', 2);
        $response->assertJsonPath('virtual_machine.ram_mb', 2048);
        $response->assertJsonPath('virtual_machine.disk_gb', 40);
        $response->assertJsonPath('virtual_machine.instance_type', 'virtual-machine');
        $response->assertJsonPath('virtual_machine.vps_plan.id', $plan->id);
        $response->assertJsonPath('virtual_machine.vps_plan.name', 'Starter Plan');
        $response->assertJsonPath('virtual_machine.vps_plan.slug', 'starter-plan');
        $response->assertJsonPath('virtual_machine.vps_plan.version_id', $version->id);
        $response->assertJsonPath('virtual_machine.vps_plan.version', 1);
        $response->assertJsonPath('virtual_machine.vps_plan.is_current_version', true);
        $response->assertJsonPath('virtual_machine.network_limits.download_mbps', 500);
        $response->assertJsonPath('virtual_machine.network_limits.upload_mbps', 250);
        $response->assertJsonPath('virtual_machine.network_limits.applicable', true);

        $this->assertDatabaseHas('virtual_machines', [
            'name' => 'web-server-01',
            'user_id' => $this->user->id,
            'vps_plan_version_id' => $version->id,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 40,
            'net_download_mbps' => 500,
            'net_upload_mbps' => 250,
        ]);

        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::where('name', 'web-server-01')->firstOrFail();
        $spec = app(InstanceSpecBuilder::class)->build($vm);
        $this->assertSame('500Mbit', $spec['devices']['eth0']['limits.ingress'] ?? null);
        $this->assertSame('250Mbit', $spec['devices']['eth0']['limits.egress'] ?? null);
    }

    public function test_provisioning_is_rejected_when_quota_is_exceeded(): void
    {
        ['plan' => $smallPlan] = $this->createPublishedPlan([
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 40,
        ]);
        ['plan' => $largePlan] = $this->createPublishedPlan([
            'cpu_cores' => 4,
            'ram_mb' => 1024,
            'disk_gb' => 20,
        ]);

        // First VM consumes 2 cores, 2048 MB RAM
        $this->actingAs($this->user)
            ->postJson('/api/v1/vms', [
                'name' => 'vm-one',
                'vps_plan_id' => $smallPlan->id,
                'os_image' => 'images:debian/12',
            ])
            ->assertStatus(202);

        // Second VM attempts 4 cores (Total 6 > 4 max_cpu_cores)
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/vms', [
                'name' => 'vm-two',
                'vps_plan_id' => $largePlan->id,
                'os_image' => 'images:debian/12',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cpu_cores']);
    }

    public function test_user_can_execute_actions_on_virtual_machine(): void
    {
        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'action-vm',
            'incus_project' => 'user-1',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        // Stop VM
        $stopResponse = $this->actingAs($this->user)
            ->postJson(sprintf('/api/v1/vms/%d/action', $vm->id), [
                'action' => 'stop',
            ]);

        $stopResponse->assertStatus(200);
        $this->assertEquals('stopped', $vm->fresh()->status::$name);

        // Start VM
        $startResponse = $this->actingAs($this->user)
            ->postJson(sprintf('/api/v1/vms/%d/action', $vm->id), [
                'action' => 'start',
            ]);

        $startResponse->assertStatus(200);
        $this->assertEquals('running', $vm->fresh()->status::$name);
    }

    public function test_user_can_suspend_and_resume_virtual_machine(): void
    {
        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'suspendable-vm',
            'incus_project' => 'user-1',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        // Suspend VM
        $suspendResponse = $this->actingAs($this->user)
            ->postJson(sprintf('/api/v1/vms/%d/action', $vm->id), [
                'action' => 'suspend',
            ]);

        $suspendResponse->assertStatus(200);
        $this->assertEquals('suspended', $vm->fresh()->status::$name);

        // Resume / Start VM from suspended state
        $resumeResponse = $this->actingAs($this->user)
            ->postJson(sprintf('/api/v1/vms/%d/action', $vm->id), [
                'action' => 'start',
            ]);

        $resumeResponse->assertStatus(200);
        $this->assertEquals('running', $vm->fresh()->status::$name);
    }

    public function test_infinite_quota_allows_unlimited_provisioning(): void
    {
        ['plan' => $largePlan] = $this->createPublishedPlan([
            'cpu_cores' => 8,
            'ram_mb' => 8192,
            'disk_gb' => 100,
        ]);

        // Set user quotas to -1 (infinite)
        $this->user->quota()->update([
            'max_vms' => -1,
            'max_cpu_cores' => -1,
            'max_ram_mb' => -1,
            'max_storage_gb' => -1,
            'max_ips' => -1,
        ]);

        // Provision 3 VMs without being blocked by previous limit of 2 VMs
        for ($i = 1; $i <= 3; $i++) {
            $response = $this->actingAs($this->user)
                ->postJson('/api/v1/vms', [
                    'name' => sprintf('infinite-vm-%d', $i),
                    'vps_plan_id' => $largePlan->id,
                    'os_image' => 'images:ubuntu/24.04',
                ]);

            $response->assertStatus(202);
        }

        $this->assertEquals(3, VirtualMachine::where('user_id', $this->user->id)->count());
    }

    public function test_console_route_returns_404(): void
    {
        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'console-vm',
            'incus_project' => 'user-1',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(sprintf('/api/v1/vms/%d/console', $vm->id));

        $response->assertStatus(404);
    }

    public function test_user_cannot_provision_duplicate_vm_name_on_same_host(): void
    {
        $host = Host::firstOrFail();
        ['plan' => $plan] = $this->createPublishedPlan([
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
        ]);

        VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $host->id,
            'name' => 'unique-test-vm',
            'incus_project' => 'proj-test',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/vms', [
                'name' => 'unique-test-vm',
                'host_id' => $host->id,
                'vps_plan_id' => $plan->id,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_user_can_provision_same_vm_name_on_different_host(): void
    {
        Queue::fake();

        ['plan' => $plan] = $this->createPublishedPlan([
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
        ]);

        $host1 = Host::firstOrFail();
        $host2 = Host::create([
            'name' => 'test-node-2',
            'endpoint_url' => 'https://127.0.0.2:8443',
            'driver' => 'fake',
            'is_active' => true,
            'total_cpu_cores' => 64,
            'total_ram_mb' => 131072,
            'total_storage_gb' => 4000,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);

        IpPool::create([
            'name' => 'Test Pool 2',
            'subnet' => '10.0.1.0/24',
            'host_id' => $host2->id,
            'is_public' => true,
        ]);

        VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $host1->id,
            'name' => 'shared-name-vm',
            'incus_project' => 'proj-test',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/vms', [
                'name' => 'shared-name-vm',
                'host_id' => $host2->id,
                'vps_plan_id' => $plan->id,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $response->assertStatus(202);
        $this->assertEquals(2, VirtualMachine::where('user_id', $this->user->id)->where('name', 'shared-name-vm')->count());
    }

    public function test_user_can_delete_virtual_machine_and_atomically_release_ip_address(): void
    {
        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'to-delete-vm',
            'incus_project' => 'user-1',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        /** @var IpAllocation $ip */
        $ip = IpAllocation::create([
            'pool_id' => $this->pool->id,
            'address' => inet_pton('192.168.1.55'),
            'ip_string' => '192.168.1.55',
            'status' => 'allocated',
            'user_id' => $this->user->id,
            'virtual_machine_id' => $vm->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(sprintf('/api/v1/vms/%d/action', $vm->id), [
                'action' => 'delete',
            ]);

        $response->assertStatus(200);

        // VM status transitions to deleted
        $this->assertEquals('deleted', $vm->fresh()->status::$name);

        // IP address is atomically released into cooling quarantine
        $freshIp = $ip->fresh();
        $this->assertSame(IpAllocationStatus::Cooling, $freshIp->status);
        $this->assertNull($freshIp->virtual_machine_id);
    }

    public function test_force_delete_vm_releases_ip_resources(): void
    {
        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'failed-vm-force-delete',
            'incus_project' => 'user-1',
            'instance_type' => 'virtual-machine',
            'status' => Failed::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        /** @var IpAllocation $ip */
        $ip = IpAllocation::create([
            'pool_id' => $this->pool->id,
            'address' => inet_pton('192.168.1.99'),
            'ip_string' => '192.168.1.99',
            'status' => 'allocated',
            'user_id' => $this->user->id,
            'virtual_machine_id' => $vm->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(sprintf('/api/v1/vms/%d/action', $vm->id), [
                'action' => 'delete',
                'force' => true,
            ]);

        $response->assertStatus(200);
        $this->assertEquals('deleted', $vm->fresh()->status::$name);
        $this->assertSame(IpAllocationStatus::Cooling, $ip->fresh()->status);
        $this->assertNull($ip->fresh()->virtual_machine_id);
    }

    public function test_unauthorized_user_cannot_view_or_action_foreign_vm(): void
    {
        $otherUser = User::factory()->create();

        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'secret-vm',
            'incus_project' => 'user-1',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        // Unauthorized user attempts show
        $this->actingAs($otherUser)
            ->getJson("/api/v1/vms/{$vm->id}")
            ->assertStatus(403);

        // Unauthorized user attempts action
        $this->actingAs($otherUser)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'stop',
                'force' => false,
            ])
            ->assertStatus(403);
    }

    public function test_user_can_reuse_vm_name_on_same_host_after_previous_vm_is_deleted(): void
    {
        Queue::fake();

        ['plan' => $plan] = $this->createPublishedPlan([
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
        ]);

        VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'reusable-vm',
            'incus_project' => 'proj-test',
            'status' => Deleted::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/vms', [
                'name' => 'reusable-vm',
                'host_id' => $this->host->id,
                'vps_plan_id' => $plan->id,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $response->assertStatus(202);
    }

    public function test_auto_selected_host_rejects_duplicate_active_vm_name_with_422(): void
    {
        ['plan' => $plan] = $this->createPublishedPlan([
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
        ]);

        VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'auto-host-dup',
            'incus_project' => 'proj-test',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/vms', [
                'name' => 'auto-host-dup',
                'vps_plan_id' => $plan->id,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_non_admin_submitting_custom_resources_is_rejected_with_422_resource_override_not_allowed(): void
    {
        ['plan' => $plan] = $this->createPublishedPlan();

        $overridePayloads = [
            ['cpu_cores' => 2],
            ['ram_mb' => 2048],
            ['disk_gb' => 20],
            ['instance_type' => 'container'],
            ['net_download_mbps' => 100],
            ['net_upload_mbps' => 100],
        ];

        foreach ($overridePayloads as $index => $override) {
            $response = $this->actingAs($this->user)
                ->postJson('/api/v1/vms', array_merge([
                    'name' => "override-vm-{$index}",
                    'vps_plan_id' => $plan->id,
                    'os_image' => 'images:ubuntu/24.04',
                ], $override));

            $response->assertStatus(422);
            $response->assertJsonPath('code', 'RESOURCE_OVERRIDE_NOT_ALLOWED');
        }

        // Also rejected when vps_plan_id is omitted and custom resources are supplied
        $responseWithoutPlan = $this->actingAs($this->user)
            ->postJson('/api/v1/vms', [
                'name' => 'override-no-plan',
                'cpu_cores' => 2,
                'ram_mb' => 2048,
                'disk_gb' => 20,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $responseWithoutPlan->assertStatus(422);
        $responseWithoutPlan->assertJsonPath('code', 'RESOURCE_OVERRIDE_NOT_ALLOWED');
    }

    public function test_non_admin_without_vps_plan_id_is_rejected_with_422(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/vms', [
                'name' => 'missing-plan-vm',
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['vps_plan_id']);
    }

    public function test_unavailable_plan_or_version_is_rejected_with_422(): void
    {
        /** @var VpsPlan $draftPlan */
        $draftPlan = VpsPlan::factory()->create(['status' => PlanDraft::class]);

        $draftRes = $this->actingAs($this->user)
            ->postJson('/api/v1/vms', [
                'name' => 'draft-plan-vm',
                'vps_plan_id' => $draftPlan->id,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $draftRes->assertStatus(422);
        $draftRes->assertJsonPath('code', 'PLAN_NOT_AVAILABLE');

        /** @var VpsPlan $publishedNoVersionPlan */
        $publishedNoVersionPlan = VpsPlan::factory()->create([
            'status' => PlanPublished::class,
            'current_version_id' => null,
        ]);

        $noVerRes = $this->actingAs($this->user)
            ->postJson('/api/v1/vms', [
                'name' => 'no-version-vm',
                'vps_plan_id' => $publishedNoVersionPlan->id,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $noVerRes->assertStatus(422);
        $noVerRes->assertJsonPath('code', 'VERSION_NOT_AVAILABLE');

        /** @var VpsPlan $publishedDraftVerPlan */
        $publishedDraftVerPlan = VpsPlan::factory()->create(['status' => PlanPublished::class]);
        /** @var VpsPlanVersion $draftVersion */
        $draftVersion = VpsPlanVersion::factory()->create([
            'vps_plan_id' => $publishedDraftVerPlan->id,
            'status' => VersionDraft::class,
        ]);
        $publishedDraftVerPlan->forceFill(['current_version_id' => $draftVersion->id])->save();

        $draftVerRes = $this->actingAs($this->user)
            ->postJson('/api/v1/vms', [
                'name' => 'draft-version-vm',
                'vps_plan_id' => $publishedDraftVerPlan->id,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $draftVerRes->assertStatus(422);
        $draftVerRes->assertJsonPath('code', 'VERSION_NOT_AVAILABLE');
    }

    public function test_admin_can_provision_in_custom_mode_without_plan(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/vms', [
                'name' => 'admin-custom-vm',
                'instance_type' => 'virtual-machine',
                'cpu_cores' => 4,
                'ram_mb' => 4096,
                'disk_gb' => 50,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $response->assertStatus(202);
        $response->assertJsonPath('virtual_machine.name', 'admin-custom-vm');
        $response->assertJsonPath('virtual_machine.cpu_cores', 4);
        $response->assertJsonPath('virtual_machine.ram_mb', 4096);
        $response->assertJsonPath('virtual_machine.disk_gb', 50);
        $response->assertJsonPath('virtual_machine.vps_plan', null);
        $response->assertJsonPath('virtual_machine.network_limits.download_mbps', null);
        $response->assertJsonPath('virtual_machine.network_limits.upload_mbps', null);
        $response->assertJsonPath('virtual_machine.network_limits.applicable', true);

        $this->assertDatabaseHas('virtual_machines', [
            'name' => 'admin-custom-vm',
            'user_id' => $this->admin->id,
            'vps_plan_version_id' => null,
            'net_download_mbps' => null,
            'net_upload_mbps' => null,
        ]);
    }

    public function test_limited_plan_is_rejected_on_manual_only_host_with_422_and_skips_manual_pools(): void
    {
        // Replace default routed pool with a manual-only pool on the host
        $this->pool->delete();

        $manualPool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Manual Only Pool',
            'cidr' => '10.10.0.0/24',
            'first_usable' => '10.10.0.10',
            'last_usable' => '10.10.0.50',
            'network_mode' => 'manual',
            'parent_interface' => '',
            'active' => true,
        ]);

        ['plan' => $limitedPlan] = $this->createPublishedPlan([
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'net_download_mbps' => 200,
            'net_upload_mbps' => 100,
        ]);

        // Auto-selected host
        $autoRes = $this->actingAs($this->user)
            ->postJson('/api/v1/vms', [
                'name' => 'limited-manual-auto',
                'vps_plan_id' => $limitedPlan->id,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $autoRes->assertStatus(422);
        $autoRes->assertJsonPath('code', 'NETWORK_LIMIT_NOT_SUPPORTED_MODE');

        // Explicit host_id
        $explicitRes = $this->actingAs($this->user)
            ->postJson('/api/v1/vms', [
                'name' => 'limited-manual-explicit',
                'host_id' => $this->host->id,
                'vps_plan_id' => $limitedPlan->id,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $explicitRes->assertStatus(422);
        $explicitRes->assertJsonPath('code', 'NETWORK_LIMIT_NOT_SUPPORTED_MODE');

        // Unlimited plan succeeds on manual-only host and reports network_limits.applicable = false
        ['plan' => $unlimitedPlan] = $this->createPublishedPlan([
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'net_download_mbps' => null,
            'net_upload_mbps' => null,
        ]);

        $unlimitedRes = $this->actingAs($this->user)
            ->postJson('/api/v1/vms', [
                'name' => 'unlimited-manual-ok',
                'host_id' => $this->host->id,
                'vps_plan_id' => $unlimitedPlan->id,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $unlimitedRes->assertStatus(202);
        $unlimitedRes->assertJsonPath('virtual_machine.network_limits.applicable', false);

        // If a routed pool is added after the manual pool, limited plan skips manualPool and allocates from routedPool
        $routedPool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Routed Secondary Pool',
            'cidr' => '10.20.0.0/24',
            'first_usable' => '10.20.0.10',
            'last_usable' => '10.20.0.50',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
            'active' => true,
        ]);

        $mixedRes = $this->actingAs($this->user)
            ->postJson('/api/v1/vms', [
                'name' => 'limited-mixed-host',
                'host_id' => $this->host->id,
                'vps_plan_id' => $limitedPlan->id,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $mixedRes->assertStatus(202);
        $mixedRes->assertJsonPath('virtual_machine.network_limits.applicable', true);

        $allocatedVm = VirtualMachine::where('name', 'limited-mixed-host')->firstOrFail();
        $this->assertSame($routedPool->id, $allocatedVm->primaryAllocation?->pool_id);
        $this->assertNotSame($manualPool->id, $allocatedVm->primaryAllocation?->pool_id);
    }

    public function test_plan_provisioning_is_forbidden_while_impersonating_and_audited(): void
    {
        ['plan' => $plan] = $this->createPublishedPlan();

        $cookies = [];
        $xsrfToken = null;

        $csrfResponse = $this->withHeader('Origin', 'http://localhost')
            ->get('/sanctum/csrf-cookie');
        $csrfResponse->assertNoContent();
        foreach ($csrfResponse->headers->getCookies() as $cookie) {
            if ($cookie->getValue() !== null) {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }
        }
        $xsrf = $csrfResponse->getCookie('XSRF-TOKEN')?->getValue();
        if (is_string($xsrf) && $xsrf !== '') {
            $xsrfToken = $xsrf;
        }

        $loginRes = $this->withCredentials()
            ->withUnencryptedCookies($cookies)
            ->withHeaders(['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => (string) $xsrfToken])
            ->postJson('/api/v1/login', [
                'email' => $this->admin->email,
                'password' => 'password',
            ]);
        $loginRes->assertOk();
        foreach ($loginRes->headers->getCookies() as $cookie) {
            if ($cookie->getValue() !== null) {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }
        }
        $xsrf = $loginRes->getCookie('XSRF-TOKEN')?->getValue();
        if (is_string($xsrf) && $xsrf !== '') {
            $xsrfToken = $xsrf;
        }

        $impRes = $this->withCredentials()
            ->withUnencryptedCookies($cookies)
            ->withHeaders(['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => (string) $xsrfToken])
            ->postJson("/api/v1/admin/users/{$this->user->id}/impersonate");
        $impRes->assertOk();
        foreach ($impRes->headers->getCookies() as $cookie) {
            if ($cookie->getValue() !== null) {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }
        }
        $xsrf = $impRes->getCookie('XSRF-TOKEN')?->getValue();
        if (is_string($xsrf) && $xsrf !== '') {
            $xsrfToken = $xsrf;
        }

        $this->app['auth']->forgetGuards();

        $response = $this->withCredentials()
            ->withUnencryptedCookies($cookies)
            ->withHeaders(['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => (string) $xsrfToken])
            ->postJson('/api/v1/vms', [
                'name' => 'impersonated-vm',
                'vps_plan_id' => $plan->id,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $response->assertStatus(403);

        /** @var AuditLog|null $deniedLog */
        $deniedLog = AuditLog::where('action', AuditAction::VmActionDenied->value)
            ->where('outcome', AuditOutcome::Denied->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($deniedLog);
        $this->assertSame($this->admin->id, $deniedLog->impersonator_id);
        $this->assertSame($this->user->id, $deniedLog->tenant_user_id);
        $this->assertDatabaseMissing('virtual_machines', ['name' => 'impersonated-vm']);
    }

    public function test_service_token_requests_are_forbidden_from_provisioning_vms(): void
    {
        ['plan' => $plan] = $this->createPublishedPlan();
        $generated = ServiceToken::generate('automation-ci', ['*']);

        // HTTP request carrying a service token bearer header is rejected with 403
        $response = $this->actingAs($this->user)
            ->withHeader('Authorization', 'Bearer '.$generated['plain_text_token'])
            ->postJson('/api/v1/vms', [
                'name' => 'service-token-vm',
                'vps_plan_id' => $plan->id,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $response->assertStatus(403);

        // Request marked via AuthenticateServiceToken attribute is also rejected by CreateVmRequest::authorize()
        $formRequest = CreateVmRequest::create('/api/v1/vms', 'POST', [
            'name' => 'service-token-attr-vm',
            'vps_plan_id' => $plan->id,
            'os_image' => 'images:ubuntu/24.04',
        ]);
        $formRequest->setUserResolver(fn (): User => $this->user);
        $formRequest->attributes->set('service_token', $generated['token']);

        $this->assertFalse($formRequest->authorize());
    }
}
