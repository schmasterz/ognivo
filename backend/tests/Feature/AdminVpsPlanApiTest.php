<?php

declare(strict_types=1);

use App\Models\Host;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Models\VpsPlan;
use App\Models\VpsPlanVersion;
use App\States\Host\Ok as HostOk;
use App\States\Vm\Deleted as VmDeleted;
use App\States\Vm\Running as VmRunning;
use App\States\Vm\Stopped as VmStopped;
use App\States\VpsPlan\Draft as PlanDraft;
use Spatie\Permission\Models\Role;

function makeVpsPlanAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $user = User::factory()->create([
        'email' => 'vps-admin@example.com',
    ]);
    $user->assignRole('admin');

    return $user;
}

function makeVpsPlanCustomer(): User
{
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $user = User::factory()->create([
        'email' => 'vps-customer@example.com',
    ]);
    $user->assignRole('customer');

    return $user;
}

function makePublishableHost(array $hostOverrides = [], string $poolMode = 'routed'): Host
{
    $host = Host::factory()->create(array_merge([
        'is_active' => true,
        'status' => HostOk::class,
        'capacity_source' => 'discovered',
        'total_cpu_cores' => 16,
        'total_ram_mb' => 16384,
        'total_storage_gb' => 500,
    ], $hostOverrides));

    IpPool::factory()->create([
        'host_id' => $host->id,
        'active' => true,
        'network_mode' => $poolMode,
    ]);

    return $host;
}

it('enforces authentication and vps.plans.manage authorization on admin endpoints', function (): void {
    $plan = VpsPlan::factory()->create();
    $customer = makeVpsPlanCustomer();

    $this->getJson('/api/v1/admin/vps/plans')->assertStatus(401);
    $this->postJson('/api/v1/admin/vps/plans', [])->assertStatus(401);
    $this->getJson("/api/v1/admin/vps/plans/{$plan->id}")->assertStatus(401);
    $this->patchJson("/api/v1/admin/vps/plans/{$plan->id}", [])->assertStatus(401);
    $this->postJson("/api/v1/admin/vps/plans/{$plan->id}/publish")->assertStatus(401);
    $this->postJson("/api/v1/admin/vps/plans/{$plan->id}/unpublish")->assertStatus(401);
    $this->postJson("/api/v1/admin/vps/plans/{$plan->id}/archive")->assertStatus(401);
    $this->postJson("/api/v1/admin/vps/plans/{$plan->id}/unarchive")->assertStatus(401);
    $this->getJson("/api/v1/admin/vps/plans/{$plan->id}/versions")->assertStatus(401);
    $this->putJson("/api/v1/admin/vps/plans/{$plan->id}/versions/draft", [])->assertStatus(401);
    $this->deleteJson("/api/v1/admin/vps/plans/{$plan->id}/versions/draft")->assertStatus(401);
    $this->postJson("/api/v1/admin/vps/plans/{$plan->id}/versions/draft/publish")->assertStatus(401);
    $this->getJson("/api/v1/admin/vps/plans/{$plan->id}/usage")->assertStatus(401);

    $this->actingAs($customer)->getJson('/api/v1/admin/vps/plans')->assertStatus(403);
    $this->actingAs($customer)->postJson('/api/v1/admin/vps/plans', [
        'name' => 'Starter',
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'virtual-machine',
    ])->assertStatus(403);
    $this->actingAs($customer)->getJson("/api/v1/admin/vps/plans/{$plan->id}")->assertStatus(403);
    $this->actingAs($customer)->patchJson("/api/v1/admin/vps/plans/{$plan->id}", ['name' => 'Updated'])->assertStatus(403);
    $this->actingAs($customer)->postJson("/api/v1/admin/vps/plans/{$plan->id}/publish")->assertStatus(403);
    $this->actingAs($customer)->postJson("/api/v1/admin/vps/plans/{$plan->id}/unpublish")->assertStatus(403);
    $this->actingAs($customer)->postJson("/api/v1/admin/vps/plans/{$plan->id}/archive")->assertStatus(403);
    $this->actingAs($customer)->postJson("/api/v1/admin/vps/plans/{$plan->id}/unarchive")->assertStatus(403);
    $this->actingAs($customer)->getJson("/api/v1/admin/vps/plans/{$plan->id}/versions")->assertStatus(403);
    $this->actingAs($customer)->putJson("/api/v1/admin/vps/plans/{$plan->id}/versions/draft", [
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'virtual-machine',
    ])->assertStatus(403);
    $this->actingAs($customer)->deleteJson("/api/v1/admin/vps/plans/{$plan->id}/versions/draft")->assertStatus(403);
    $this->actingAs($customer)->postJson("/api/v1/admin/vps/plans/{$plan->id}/versions/draft/publish")->assertStatus(403);
    $this->actingAs($customer)->getJson("/api/v1/admin/vps/plans/{$plan->id}/usage")->assertStatus(403);
});

it('creates a draft plan with initial draft version, validates ResourceBounds, and lists/filters plans', function (): void {
    $admin = makeVpsPlanAdmin();

    // Validation bounds check
    $this->actingAs($admin)
        ->postJson('/api/v1/admin/vps/plans', [
            'name' => 'Invalid Plan',
            'cpu_cores' => 0,
            'ram_mb' => 128,
            'disk_gb' => 1,
            'instance_type' => 'invalid-type',
            'net_download_mbps' => 0,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'cpu_cores',
            'ram_mb',
            'disk_gb',
            'instance_type',
            'net_download_mbps',
        ]);

    $createResponse = $this->actingAs($admin)
        ->postJson('/api/v1/admin/vps/plans', [
            'name' => 'Starter Pro',
            'description' => 'Balanced starter plan',
            'sort_order' => 10,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 25,
            'instance_type' => 'virtual-machine',
            'net_download_mbps' => 500,
            'net_upload_mbps' => 250,
            'change_summary' => 'Initial launch spec',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'Starter Pro')
        ->assertJsonPath('data.slug', 'starter-pro')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.sort_order', 10)
        ->assertJsonPath('data.current_version_id', null)
        ->assertJsonPath('data.current_version', null)
        ->assertJsonPath('data.draft_version.status', 'draft')
        ->assertJsonPath('data.draft_version.version', null)
        ->assertJsonPath('data.draft_version.cpu_cores', 2)
        ->assertJsonPath('data.draft_version.net_download_mbps', 500)
        ->assertJsonPath('data.draft_version.change_summary', 'Initial launch spec');

    $planId = $createResponse->json('data.id');

    $this->actingAs($admin)
        ->getJson("/api/v1/admin/vps/plans/{$planId}")
        ->assertOk()
        ->assertJsonPath('data.id', $planId)
        ->assertJsonPath('data.draft_version.ram_mb', 2048);

    // Second plan in published status to verify admin list filters
    $publishedPlan = VpsPlan::factory()->published()->create([
        'name' => 'Enterprise Compute',
        'slug' => 'enterprise-compute',
        'sort_order' => 20,
    ]);
    $publishedVersion = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $publishedPlan->id,
        'version' => 1,
    ]);
    $publishedPlan->update(['current_version_id' => $publishedVersion->id]);

    $this->actingAs($admin)
        ->getJson('/api/v1/admin/vps/plans')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($admin)
        ->getJson('/api/v1/admin/vps/plans?status=published')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'enterprise-compute');

    $this->actingAs($admin)
        ->getJson('/api/v1/admin/vps/plans?search=starter')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'starter-pro');
});

it('updates metadata without bumping version and enforces slug immutability after publishing a version', function (): void {
    $admin = makeVpsPlanAdmin();
    makePublishableHost();

    $createResponse = $this->actingAs($admin)
        ->postJson('/api/v1/admin/vps/plans', [
            'name' => 'Basic Tier',
            'slug' => 'basic-tier',
            'description' => 'Old description',
            'sort_order' => 1,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'instance_type' => 'virtual-machine',
        ])
        ->assertStatus(201);

    $planId = $createResponse->json('data.id');

    // Slug can be changed before any version is published
    $this->actingAs($admin)
        ->patchJson("/api/v1/admin/vps/plans/{$planId}", [
            'name' => 'Basic Tier Renamed',
            'slug' => 'basic-tier-v1',
            'description' => 'Updated description',
            'sort_order' => 5,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Basic Tier Renamed')
        ->assertJsonPath('data.slug', 'basic-tier-v1')
        ->assertJsonPath('data.description', 'Updated description')
        ->assertJsonPath('data.sort_order', 5);

    expect(VpsPlanVersion::query()->where('vps_plan_id', $planId)->count())->toBe(1);

    // Publish plan (publishes initial draft as version 1)
    $publishResponse = $this->actingAs($admin)
        ->postJson("/api/v1/admin/vps/plans/{$planId}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.current_version.version', 1);

    $currentVersionId = $publishResponse->json('data.current_version_id');

    // Metadata edit after publish does NOT bump version
    $this->actingAs($admin)
        ->patchJson("/api/v1/admin/vps/plans/{$planId}", [
            'name' => 'Basic Tier Marketing Name',
            'description' => 'Marketing update',
            'sort_order' => 99,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Basic Tier Marketing Name')
        ->assertJsonPath('data.current_version_id', $currentVersionId)
        ->assertJsonPath('data.current_version.version', 1);

    expect(VpsPlanVersion::query()->where('vps_plan_id', $planId)->count())->toBe(1);

    // Slug change is forbidden once a version has been published
    $this->actingAs($admin)
        ->patchJson("/api/v1/admin/vps/plans/{$planId}", [
            'slug' => 'another-slug',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['slug']);
});

it('supports draft version upsert, discard, and publish superseding previous versions', function (): void {
    $admin = makeVpsPlanAdmin();
    makePublishableHost();

    $createResponse = $this->actingAs($admin)
        ->postJson('/api/v1/admin/vps/plans', [
            'name' => 'Compute Plan',
            'slug' => 'compute-plan',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'instance_type' => 'virtual-machine',
        ])
        ->assertStatus(201);

    $planId = $createResponse->json('data.id');

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/vps/plans/{$planId}/publish")
        ->assertOk()
        ->assertJsonPath('data.current_version.version', 1);

    // Upsert a draft version on a published plan without affecting current_version
    $this->actingAs($admin)
        ->putJson("/api/v1/admin/vps/plans/{$planId}/versions/draft", [
            'cpu_cores' => 4,
            'ram_mb' => 4096,
            'disk_gb' => 40,
            'instance_type' => 'virtual-machine',
            'change_summary' => 'Upgrade to 4 vCPU',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.version', null)
        ->assertJsonPath('data.cpu_cores', 4);

    // Replace existing draft
    $this->actingAs($admin)
        ->putJson("/api/v1/admin/vps/plans/{$planId}/versions/draft", [
            'cpu_cores' => 6,
            'ram_mb' => 6144,
            'disk_gb' => 60,
            'instance_type' => 'virtual-machine',
            'change_summary' => 'Upgrade to 6 vCPU',
        ])
        ->assertOk()
        ->assertJsonPath('data.cpu_cores', 6);

    // Discard draft without consuming version number
    $this->actingAs($admin)
        ->deleteJson("/api/v1/admin/vps/plans/{$planId}/versions/draft")
        ->assertOk()
        ->assertJsonPath('data.discarded', true);

    // Discarding again when none exists returns 404
    $this->actingAs($admin)
        ->deleteJson("/api/v1/admin/vps/plans/{$planId}/versions/draft")
        ->assertStatus(404);

    // Publishing draft when none exists returns 422 NO_PUBLISHABLE_VERSION
    $this->actingAs($admin)
        ->postJson("/api/v1/admin/vps/plans/{$planId}/versions/draft/publish")
        ->assertStatus(422)
        ->assertJsonPath('errors.version.0', 'NO_PUBLISHABLE_VERSION');

    // Create new draft and publish it -> becomes version 2, superseding version 1
    $this->actingAs($admin)
        ->putJson("/api/v1/admin/vps/plans/{$planId}/versions/draft", [
            'cpu_cores' => 8,
            'ram_mb' => 8192,
            'disk_gb' => 80,
            'instance_type' => 'virtual-machine',
            'change_summary' => 'Double resources',
        ])
        ->assertCreated();

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/vps/plans/{$planId}/versions/draft/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.cpu_cores', 8);

    $versionsResponse = $this->actingAs($admin)
        ->getJson("/api/v1/admin/vps/plans/{$planId}/versions")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect($versionsResponse->json('data.0.version'))->toBe(2)
        ->and($versionsResponse->json('data.0.status'))->toBe('published')
        ->and($versionsResponse->json('data.1.version'))->toBe(1)
        ->and($versionsResponse->json('data.1.status'))->toBe('superseded');
});

it('returns 409 on invalid plan state transitions and 422 when publish eligibility fails', function (): void {
    $admin = makeVpsPlanAdmin();

    // Draft plan with no versions cannot be published -> 422 NO_PUBLISHABLE_VERSION
    $emptyDraft = VpsPlan::factory()->create(['status' => PlanDraft::class]);
    $this->actingAs($admin)
        ->postJson("/api/v1/admin/vps/plans/{$emptyDraft->id}/publish")
        ->assertStatus(422)
        ->assertJsonPath('errors.version.0', 'NO_PUBLISHABLE_VERSION');

    // Draft plan exceeding host capacity or missing non-manual pool -> 422
    makePublishableHost([
        'total_cpu_cores' => 4,
        'total_ram_mb' => 4096,
        'total_storage_gb' => 100,
    ], 'manual');

    $tooBigPlan = VpsPlan::factory()->create(['status' => PlanDraft::class]);
    VpsPlanVersion::factory()->create([
        'vps_plan_id' => $tooBigPlan->id,
        'cpu_cores' => 8,
        'ram_mb' => 8192,
        'disk_gb' => 200,
        'net_download_mbps' => null,
        'net_upload_mbps' => null,
    ]);
    $this->actingAs($admin)
        ->postJson("/api/v1/admin/vps/plans/{$tooBigPlan->id}/publish")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cpu_cores']);

    $manualPoolLimitedPlan = VpsPlan::factory()->create(['status' => PlanDraft::class]);
    VpsPlanVersion::factory()->create([
        'vps_plan_id' => $manualPoolLimitedPlan->id,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'net_download_mbps' => 500,
        'net_upload_mbps' => 500,
    ]);
    $this->actingAs($admin)
        ->postJson("/api/v1/admin/vps/plans/{$manualPoolLimitedPlan->id}/publish")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['net_download_mbps']);

    // Add routed pool host so lifecycle transitions can be tested
    makePublishableHost([], 'routed');

    $plan = VpsPlan::factory()->create(['status' => PlanDraft::class]);
    VpsPlanVersion::factory()->create([
        'vps_plan_id' => $plan->id,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'net_download_mbps' => 500,
        'net_upload_mbps' => 500,
    ]);

    // Invalid transitions from draft
    $this->actingAs($admin)->postJson("/api/v1/admin/vps/plans/{$plan->id}/unpublish")->assertStatus(409);
    $this->actingAs($admin)->postJson("/api/v1/admin/vps/plans/{$plan->id}/unarchive")->assertStatus(409);

    // Publish -> cannot publish again
    $this->actingAs($admin)
        ->postJson("/api/v1/admin/vps/plans/{$plan->id}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    $this->actingAs($admin)->postJson("/api/v1/admin/vps/plans/{$plan->id}/publish")->assertStatus(409);

    // Unpublish -> draft
    $this->actingAs($admin)
        ->postJson("/api/v1/admin/vps/plans/{$plan->id}/unpublish")
        ->assertOk()
        ->assertJsonPath('data.status', 'draft');

    // Archive -> cannot archive again, cannot publish, cannot publish draft version
    $this->actingAs($admin)
        ->postJson("/api/v1/admin/vps/plans/{$plan->id}/archive")
        ->assertOk()
        ->assertJsonPath('data.status', 'archived');

    $this->actingAs($admin)->postJson("/api/v1/admin/vps/plans/{$plan->id}/archive")->assertStatus(409);
    $this->actingAs($admin)->postJson("/api/v1/admin/vps/plans/{$plan->id}/publish")->assertStatus(409);
    $this->actingAs($admin)->postJson("/api/v1/admin/vps/plans/{$plan->id}/unpublish")->assertStatus(409);
    $this->actingAs($admin)->postJson("/api/v1/admin/vps/plans/{$plan->id}/versions/draft/publish")->assertStatus(409);

    // Unarchive -> draft
    $this->actingAs($admin)
        ->postJson("/api/v1/admin/vps/plans/{$plan->id}/unarchive")
        ->assertOk()
        ->assertJsonPath('data.status', 'draft');
});

it('returns per-version active VM usage counts excluding deleted VMs', function (): void {
    $admin = makeVpsPlanAdmin();
    $customer = makeVpsPlanCustomer();

    $plan = VpsPlan::factory()->published()->create();
    $v1 = VpsPlanVersion::factory()->superseded()->create([
        'vps_plan_id' => $plan->id,
        'version' => 1,
    ]);
    $v2 = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $plan->id,
        'version' => 2,
    ]);
    $plan->update(['current_version_id' => $v2->id]);

    VirtualMachine::factory()->create([
        'user_id' => $customer->id,
        'vps_plan_version_id' => $v1->id,
        'status' => VmRunning::class,
    ]);
    VirtualMachine::factory()->create([
        'user_id' => $customer->id,
        'vps_plan_version_id' => $v1->id,
        'status' => VmDeleted::class,
    ]);
    VirtualMachine::factory()->create([
        'user_id' => $customer->id,
        'vps_plan_version_id' => $v2->id,
        'status' => VmRunning::class,
    ]);
    VirtualMachine::factory()->create([
        'user_id' => $customer->id,
        'vps_plan_version_id' => $v2->id,
        'status' => VmStopped::class,
    ]);

    $this->actingAs($admin)
        ->getJson("/api/v1/admin/vps/plans/{$plan->id}/usage")
        ->assertOk()
        ->assertJsonPath('data.vps_plan_id', $plan->id)
        ->assertJsonPath('data.total_active_vms', 3)
        ->assertJsonPath('data.versions.0.vps_plan_version_id', $v1->id)
        ->assertJsonPath('data.versions.0.version', 1)
        ->assertJsonPath('data.versions.0.active_vms_count', 1)
        ->assertJsonPath('data.versions.1.vps_plan_version_id', $v2->id)
        ->assertJsonPath('data.versions.1.version', 2)
        ->assertJsonPath('data.versions.1.active_vms_count', 2);
});
