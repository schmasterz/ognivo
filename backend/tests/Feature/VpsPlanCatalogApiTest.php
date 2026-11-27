<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\VpsPlan;
use App\Models\VpsPlanVersion;
use App\States\User\Suspended as UserSuspended;
use App\States\VpsPlan\Archived as PlanArchived;
use App\States\VpsPlan\Draft as PlanDraft;
use App\States\VpsPlan\Published as PlanPublished;
use App\States\VpsPlanVersion\Published as VersionPublished;
use Spatie\Permission\Models\Role;

function makeCatalogCustomer(): User
{
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $user = User::factory()->create();
    $user->assignRole('customer');

    return $user;
}

it('requires authentication and active status for catalog endpoints', function (): void {
    $this->getJson('/api/v1/vps/plans')->assertStatus(401);
    $this->getJson('/api/v1/vps/plans/starter')->assertStatus(401);

    $suspended = User::factory()->create([
        'status' => UserSuspended::class,
    ]);

    $this->actingAs($suspended)
        ->getJson('/api/v1/vps/plans')
        ->assertStatus(403);
});

it('lists only published plans with a current version ordered by sort_order and id', function (): void {
    $user = makeCatalogCustomer();

    $draftPlan = VpsPlan::factory()->create([
        'name' => 'Draft Plan',
        'slug' => 'draft-plan',
        'status' => PlanDraft::class,
        'sort_order' => 1,
    ]);
    VpsPlanVersion::factory()->create([
        'vps_plan_id' => $draftPlan->id,
    ]);

    $archivedPlan = VpsPlan::factory()->create([
        'name' => 'Archived Plan',
        'slug' => 'archived-plan',
        'status' => PlanArchived::class,
        'sort_order' => 1,
    ]);
    $archivedVersion = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $archivedPlan->id,
        'version' => 1,
    ]);
    $archivedPlan->update(['current_version_id' => $archivedVersion->id]);

    // Published plan without current_version_id must be excluded
    VpsPlan::factory()->published()->create([
        'name' => 'Broken Published Plan',
        'slug' => 'broken-published',
        'status' => PlanPublished::class,
        'current_version_id' => null,
        'sort_order' => 0,
    ]);

    $planB = VpsPlan::factory()->published()->create([
        'name' => 'Plan B',
        'slug' => 'plan-b',
        'description' => 'Second by sort order',
        'sort_order' => 20,
    ]);
    $planBVersion = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $planB->id,
        'version' => 1,
        'cpu_cores' => 4,
        'ram_mb' => 4096,
        'disk_gb' => 40,
        'instance_type' => 'virtual-machine',
        'net_download_mbps' => 1000,
        'net_upload_mbps' => 500,
    ]);
    $planB->update(['current_version_id' => $planBVersion->id]);

    $planA1 = VpsPlan::factory()->published()->create([
        'name' => 'Plan A1',
        'slug' => 'plan-a1',
        'description' => 'First by sort order and id',
        'sort_order' => 10,
    ]);
    $planA1Version = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $planA1->id,
        'version' => 2,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'container',
        'net_download_mbps' => null,
        'net_upload_mbps' => null,
    ]);
    $planA1->update(['current_version_id' => $planA1Version->id]);

    $planA2 = VpsPlan::factory()->published()->create([
        'name' => 'Plan A2',
        'slug' => 'plan-a2',
        'description' => 'Tie sort order with higher id',
        'sort_order' => 10,
    ]);
    $planA2Version = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $planA2->id,
        'version' => 1,
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'instance_type' => 'container',
    ]);
    $planA2->update(['current_version_id' => $planA2Version->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/vps/plans')
        ->assertOk()
        ->assertJsonCount(3, 'data');

    $items = $response->json('data');
    expect(array_column($items, 'slug'))->toBe(['plan-a1', 'plan-a2', 'plan-b']);

    // Verify public catalog schema omits internal/audit fields
    $first = $items[0];
    expect(array_keys($first))->toBe([
        'id',
        'name',
        'slug',
        'description',
        'sort_order',
        'current_version',
    ])->and(array_keys($first['current_version']))->toBe([
        'id',
        'version',
        'cpu_cores',
        'ram_mb',
        'disk_gb',
        'instance_type',
        'net_download_mbps',
        'net_upload_mbps',
    ])->and($first)->not->toHaveKeys([
        'status',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
    ])->and($first['current_version'])->not->toHaveKeys([
        'status',
        'change_summary',
        'created_by',
        'published_by',
        'created_at',
        'published_at',
    ]);
});

it('shows a published plan by slug and returns 404 for unpublished, archived, or missing slugs', function (): void {
    $user = makeCatalogCustomer();

    $draftPlan = VpsPlan::factory()->create([
        'slug' => 'hidden-draft',
        'status' => PlanDraft::class,
    ]);

    $archivedPlan = VpsPlan::factory()->archived()->create([
        'slug' => 'old-archived',
    ]);
    $archivedVersion = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $archivedPlan->id,
        'version' => 1,
        'status' => VersionPublished::class,
    ]);
    $archivedPlan->update(['current_version_id' => $archivedVersion->id]);

    $publishedNoVersion = VpsPlan::factory()->published()->create([
        'slug' => 'no-version',
        'current_version_id' => null,
    ]);

    $publishedPlan = VpsPlan::factory()->published()->create([
        'name' => 'Starter VPS',
        'slug' => 'starter-vps',
        'description' => 'Entry tier',
        'sort_order' => 5,
    ]);
    $publishedVersion = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $publishedPlan->id,
        'version' => 1,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 25,
        'instance_type' => 'virtual-machine',
        'net_download_mbps' => 500,
        'net_upload_mbps' => 250,
    ]);
    $publishedPlan->update(['current_version_id' => $publishedVersion->id]);

    $this->actingAs($user)
        ->getJson("/api/v1/vps/plans/{$draftPlan->slug}")
        ->assertNotFound();

    $this->actingAs($user)
        ->getJson("/api/v1/vps/plans/{$archivedPlan->slug}")
        ->assertNotFound();

    $this->actingAs($user)
        ->getJson("/api/v1/vps/plans/{$publishedNoVersion->slug}")
        ->assertNotFound();

    $this->actingAs($user)
        ->getJson('/api/v1/vps/plans/does-not-exist')
        ->assertNotFound();

    $this->actingAs($user)
        ->getJson('/api/v1/vps/plans/starter-vps')
        ->assertOk()
        ->assertJsonPath('data.id', $publishedPlan->id)
        ->assertJsonPath('data.slug', 'starter-vps')
        ->assertJsonPath('data.current_version.id', $publishedVersion->id)
        ->assertJsonPath('data.current_version.version', 1)
        ->assertJsonPath('data.current_version.cpu_cores', 2)
        ->assertJsonPath('data.current_version.ram_mb', 2048)
        ->assertJsonPath('data.current_version.disk_gb', 25)
        ->assertJsonPath('data.current_version.instance_type', 'virtual-machine')
        ->assertJsonPath('data.current_version.net_download_mbps', 500)
        ->assertJsonPath('data.current_version.net_upload_mbps', 250);
});
