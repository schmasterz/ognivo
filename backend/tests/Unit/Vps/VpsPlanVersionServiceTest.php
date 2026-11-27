<?php

declare(strict_types=1);

use App\Audit\AuditAction;
use App\Events\Vps\VpsPlanArchived;
use App\Events\Vps\VpsPlanCreated;
use App\Events\Vps\VpsPlanPublished;
use App\Events\Vps\VpsPlanUnarchived;
use App\Events\Vps\VpsPlanUnpublished;
use App\Events\Vps\VpsPlanUpdated;
use App\Events\Vps\VpsPlanVersionCreated;
use App\Events\Vps\VpsPlanVersionPublished;
use App\Models\Host;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VpsPlan;
use App\Models\VpsPlanVersion;
use App\Services\Vps\VpsPlanService;
use App\Services\Vps\VpsPlanVersionService;
use App\States\Host\Ok as HostOk;
use App\States\Host\Unreachable as HostUnreachable;
use App\States\VpsPlan\Archived as PlanArchived;
use App\States\VpsPlan\Draft as PlanDraft;
use App\States\VpsPlan\Published as PlanPublished;
use App\States\VpsPlanVersion\Draft as VersionDraft;
use App\States\VpsPlanVersion\Published as VersionPublished;
use App\States\VpsPlanVersion\Superseded as VersionSuperseded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makePlanAdmin(): User
{
    return User::factory()->create([
        'email' => 'plan-admin@example.com',
        'name' => 'Plan Admin',
    ]);
}

function createEligibleHost(array $attributes = [], string $poolNetworkMode = 'routed', bool $createPool = true): Host
{
    $host = Host::factory()->create(array_merge([
        'is_active' => true,
        'status' => HostOk::class,
        'capacity_source' => 'discovered',
        'total_cpu_cores' => 16,
        'total_ram_mb' => 16384,
        'total_storage_gb' => 500,
    ], $attributes));

    if ($createPool) {
        IpPool::factory()->create([
            'host_id' => $host->id,
            'active' => true,
            'network_mode' => $poolNetworkMode,
        ]);
    }

    return $host;
}

it('creates and replaces a draft version via upsertDraft and validates ResourceBounds', function (): void {
    Event::fake();

    $actor = makePlanAdmin();
    $plan = VpsPlan::factory()->create(['status' => PlanDraft::class]);
    $versionService = app(VpsPlanVersionService::class);

    $firstDraft = $versionService->upsertDraft($plan, [
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 25,
        'instance_type' => 'virtual-machine',
        'net_download_mbps' => 500,
        'net_upload_mbps' => 500,
        'change_summary' => 'Initial draft',
    ], $actor);

    expect($firstDraft->status)->toBeInstanceOf(VersionDraft::class)
        ->and($firstDraft->version)->toBeNull()
        ->and($firstDraft->cpu_cores)->toBe(2)
        ->and($firstDraft->ram_mb)->toBe(2048)
        ->and($firstDraft->disk_gb)->toBe(25)
        ->and($firstDraft->created_by)->toBe($actor->id);

    $replacedDraft = $versionService->upsertDraft($plan, [
        'cpu_cores' => 4,
        'ram_mb' => 4096,
        'disk_gb' => 50,
        'instance_type' => 'container',
        'net_download_mbps' => 1000,
        'net_upload_mbps' => 1000,
        'change_summary' => 'Updated draft',
    ], $actor);

    expect($replacedDraft->id)->toBe($firstDraft->id)
        ->and($replacedDraft->cpu_cores)->toBe(4)
        ->and($replacedDraft->ram_mb)->toBe(4096)
        ->and($replacedDraft->disk_gb)->toBe(50)
        ->and($replacedDraft->instance_type)->toBe('container')
        ->and($replacedDraft->change_summary)->toBe('Updated draft')
        ->and(VpsPlanVersion::query()->where('vps_plan_id', $plan->id)->count())->toBe(1);

    Event::assertDispatchedTimes(VpsPlanVersionCreated::class, 2);
    expect(DB::table('audit_logs')->where('action', AuditAction::VpsPlanVersionCreated->value)->count())->toBe(2);

    expect(fn () => $versionService->upsertDraft($plan, [
        'cpu_cores' => 0,
        'ram_mb' => 256,
        'disk_gb' => 1,
        'instance_type' => 'invalid-type',
    ], $actor))->toThrow(ValidationException::class);
});

it('discards a draft version without leaving version number gaps', function (): void {
    Event::fake();

    $actor = makePlanAdmin();
    createEligibleHost();

    $plan = VpsPlan::factory()->create(['status' => PlanDraft::class]);
    $versionService = app(VpsPlanVersionService::class);

    $versionService->upsertDraft($plan, [
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'virtual-machine',
    ], $actor);

    $v1 = $versionService->publishDraft($plan, $actor);
    expect($v1->version)->toBe(1);

    $draft = $versionService->upsertDraft($plan, [
        'cpu_cores' => 4,
        'ram_mb' => 4096,
        'disk_gb' => 40,
        'instance_type' => 'virtual-machine',
    ], $actor);

    $versionService->discardDraft($plan, $actor);

    expect(VpsPlanVersion::query()->find($draft->id))->toBeNull()
        ->and(DB::table('audit_logs')->where('action', AuditAction::VpsPlanVersionDiscarded->value)->count())->toBe(1);

    expect(fn () => $versionService->discardDraft($plan, $actor))
        ->toThrow(NotFoundHttpException::class);

    $versionService->upsertDraft($plan, [
        'cpu_cores' => 6,
        'ram_mb' => 6144,
        'disk_gb' => 60,
        'instance_type' => 'virtual-machine',
    ], $actor);

    $v2 = $versionService->publishDraft($plan, $actor);
    expect($v2->version)->toBe(2);
});

it('publishes versions with MAX+1 numbering and supersedes the previous published version', function (): void {
    Event::fake();

    $actor = makePlanAdmin();
    createEligibleHost();

    $plan = VpsPlan::factory()->create(['status' => PlanDraft::class]);
    $versionService = app(VpsPlanVersionService::class);

    $versionService->upsertDraft($plan, [
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'virtual-machine',
        'net_download_mbps' => 1000,
        'net_upload_mbps' => 1000,
    ], $actor);

    $v1 = $versionService->publishDraft($plan, $actor);

    expect($v1->version)->toBe(1)
        ->and($v1->status)->toBeInstanceOf(VersionPublished::class)
        ->and($v1->published_by)->toBe($actor->id)
        ->and($v1->published_at)->not->toBeNull()
        ->and($plan->fresh()?->current_version_id)->toBe($v1->id);

    Event::assertDispatched(VpsPlanVersionPublished::class, function (VpsPlanVersionPublished $event) use ($plan, $v1): bool {
        return $event->getEventName() === 'vps.plan.version.published'
            && $event->planId === $plan->id
            && $event->versionId === $v1->id
            && $event->version === 1
            && $event->supersededVersionId === null;
    });

    $versionService->upsertDraft($plan, [
        'cpu_cores' => 4,
        'ram_mb' => 4096,
        'disk_gb' => 40,
        'instance_type' => 'virtual-machine',
        'net_download_mbps' => 2000,
        'net_upload_mbps' => 2000,
    ], $actor);

    $v2 = $versionService->publishDraft($plan, $actor);

    expect($v2->version)->toBe(2)
        ->and($v2->status)->toBeInstanceOf(VersionPublished::class)
        ->and($v1->fresh()?->status)->toBeInstanceOf(VersionSuperseded::class)
        ->and($plan->fresh()?->current_version_id)->toBe($v2->id);

    Event::assertDispatched(VpsPlanVersionPublished::class, function (VpsPlanVersionPublished $event) use ($plan, $v1, $v2): bool {
        return $event->planId === $plan->id
            && $event->versionId === $v2->id
            && $event->version === 2
            && $event->supersededVersionId === $v1->id;
    });
});

it('forbids publishDraft on an archived plan', function (): void {
    $actor = makePlanAdmin();
    createEligibleHost();

    $plan = VpsPlan::factory()->create(['status' => PlanDraft::class]);
    $versionService = app(VpsPlanVersionService::class);
    $planService = app(VpsPlanService::class);

    $versionService->upsertDraft($plan, [
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'virtual-machine',
    ], $actor);

    $archivedPlan = $planService->archive($plan, $actor);

    expect(fn () => $versionService->publishDraft($archivedPlan, $actor))
        ->toThrow(ConflictHttpException::class);
});

it('throws NO_PUBLISHABLE_VERSION when publishing a plan or draft with no publishable version', function (): void {
    $actor = makePlanAdmin();
    createEligibleHost();

    $plan = VpsPlan::factory()->create(['status' => PlanDraft::class, 'current_version_id' => null]);
    $planService = app(VpsPlanService::class);
    $versionService = app(VpsPlanVersionService::class);

    try {
        $planService->publish($plan, $actor);
        $this->fail('Expected ValidationException with NO_PUBLISHABLE_VERSION.');
    } catch (ValidationException $e) {
        expect($e->status)->toBe(422)
            ->and($e->errors()['version'] ?? [])->toContain('NO_PUBLISHABLE_VERSION');
    }

    try {
        $versionService->publishDraft($plan, $actor);
        $this->fail('Expected ValidationException with NO_PUBLISHABLE_VERSION.');
    } catch (ValidationException $e) {
        expect($e->status)->toBe(422)
            ->and($e->errors()['version'] ?? [])->toContain('NO_PUBLISHABLE_VERSION');
    }
});

it('enforces Spec 7.3 host physical capacity and non-manual network pool publish guard', function (): void {
    $actor = makePlanAdmin();
    $versionService = app(VpsPlanVersionService::class);

    $plan = VpsPlan::factory()->create(['status' => PlanDraft::class]);
    $versionService->upsertDraft($plan, [
        'cpu_cores' => 4,
        'ram_mb' => 4096,
        'disk_gb' => 50,
        'instance_type' => 'virtual-machine',
        'net_download_mbps' => 1000,
        'net_upload_mbps' => 1000,
    ], $actor);

    // 1. Inactive, unreachable, or unknown-capacity hosts are ineligible.
    createEligibleHost(['is_active' => false]);
    createEligibleHost(['status' => HostUnreachable::class]);
    createEligibleHost(['capacity_source' => 'unknown']);

    expect(fn () => $versionService->publishDraft($plan, $actor))
        ->toThrow(ValidationException::class);

    // 2. Host with overcommit but insufficient physical capacity is ineligible.
    createEligibleHost([
        'total_cpu_cores' => 4,
        'total_ram_mb' => 2048,
        'total_storage_gb' => 100,
        'ram_overcommit' => 4.0,
    ]);

    expect(fn () => $versionService->publishDraft($plan, $actor))
        ->toThrow(ValidationException::class);

    // 3. Host with sufficient physical capacity but only a manual IP pool is ineligible when network limits are set.
    $manualOnlyHost = createEligibleHost([
        'total_cpu_cores' => 8,
        'total_ram_mb' => 8192,
        'total_storage_gb' => 200,
    ], poolNetworkMode: 'manual');

    expect(fn () => $versionService->publishDraft($plan, $actor))
        ->toThrow(ValidationException::class);

    // 4. Adding an inactive routed pool on that host is still insufficient.
    IpPool::factory()->create([
        'host_id' => $manualOnlyHost->id,
        'active' => false,
        'network_mode' => 'routed',
    ]);

    expect(fn () => $versionService->publishDraft($plan, $actor))
        ->toThrow(ValidationException::class);

    // 5. Removing network limits allows publishing against the host even with manual pool.
    $versionService->upsertDraft($plan, [
        'cpu_cores' => 4,
        'ram_mb' => 4096,
        'disk_gb' => 50,
        'instance_type' => 'virtual-machine',
        'net_download_mbps' => null,
        'net_upload_mbps' => null,
    ], $actor);

    $v1 = $versionService->publishDraft($plan, $actor);
    expect($v1->version)->toBe(1);

    // 6. With network limits restored and an active non-manual pool added, publishing succeeds.
    $versionService->upsertDraft($plan, [
        'cpu_cores' => 4,
        'ram_mb' => 4096,
        'disk_gb' => 50,
        'instance_type' => 'virtual-machine',
        'net_download_mbps' => 1000,
        'net_upload_mbps' => 1000,
    ], $actor);

    IpPool::factory()->create([
        'host_id' => $manualOnlyHost->id,
        'active' => true,
        'network_mode' => 'nat',
    ]);

    $v2 = $versionService->publishDraft($plan, $actor);
    expect($v2->version)->toBe(2);
});

it('enforces slug immutability after a version has been published and manages plan lifecycle transitions', function (): void {
    Event::fake();

    $actor = makePlanAdmin();
    createEligibleHost();

    $planService = app(VpsPlanService::class);

    $plan = $planService->create([
        'name' => 'Starter VPS',
        'slug' => 'starter-vps',
        'description' => 'Entry level plan',
        'sort_order' => 1,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'virtual-machine',
    ], $actor);

    expect($plan->status)->toBeInstanceOf(PlanDraft::class)
        ->and($plan->draftVersion)->not->toBeNull();

    Event::assertDispatched(VpsPlanCreated::class);

    // Slug can be updated before any version has been published.
    $plan = $planService->updateMetadata($plan, [
        'name' => 'Starter One',
        'slug' => 'starter-one',
        'sort_order' => 5,
    ], $actor);

    expect($plan->slug)->toBe('starter-one')
        ->and($plan->name)->toBe('Starter One')
        ->and($plan->sort_order)->toBe(5);
    Event::assertDispatched(VpsPlanUpdated::class);

    // Publish plan (Draft -> Published, publishes draft version v1).
    $plan = $planService->publish($plan, $actor);
    expect($plan->status)->toBeInstanceOf(PlanPublished::class)
        ->and($plan->current_version_id)->not->toBeNull();
    Event::assertDispatched(VpsPlanPublished::class);

    // Slug change is rejected once any version has been published.
    expect(fn () => $planService->updateMetadata($plan, ['slug' => 'starter-renamed'], $actor))
        ->toThrow(ValidationException::class);

    // Unchanged slug and other metadata fields remain editable.
    $plan = $planService->updateMetadata($plan, [
        'name' => 'Starter One Plus',
        'slug' => 'starter-one',
        'description' => 'Updated copy',
    ], $actor);
    expect($plan->name)->toBe('Starter One Plus')
        ->and($plan->description)->toBe('Updated copy');

    // Unpublish (Published -> Draft), and re-publish without a new draft using current_version_id.
    $plan = $planService->unpublish($plan, $actor);
    expect($plan->status)->toBeInstanceOf(PlanDraft::class);
    Event::assertDispatched(VpsPlanUnpublished::class);

    // Slug remains immutable even after unpublishing.
    expect(fn () => $planService->updateMetadata($plan, ['slug' => 'starter-after-unpublish'], $actor))
        ->toThrow(ValidationException::class);

    $plan = $planService->publish($plan, $actor);
    expect($plan->status)->toBeInstanceOf(PlanPublished::class);

    // Archive and unarchive transitions.
    $plan = $planService->archive($plan, $actor);
    expect($plan->status)->toBeInstanceOf(PlanArchived::class);
    Event::assertDispatched(VpsPlanArchived::class);

    $plan = $planService->unarchive($plan, $actor);
    expect($plan->status)->toBeInstanceOf(PlanDraft::class);
    Event::assertDispatched(VpsPlanUnarchived::class);
});
