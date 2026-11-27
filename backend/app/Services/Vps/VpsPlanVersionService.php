<?php

declare(strict_types=1);

namespace App\Services\Vps;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\DTO\Cast;
use App\DTO\Vps\ResourceBounds;
use App\Events\Vps\VpsPlanVersionCreated;
use App\Events\Vps\VpsPlanVersionPublished;
use App\Models\Host;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VpsPlan;
use App\Models\VpsPlanVersion;
use App\Services\HostCapacityGuard;
use App\States\Host\Ok as HostOk;
use App\States\VpsPlan\Archived as PlanArchived;
use App\States\VpsPlanVersion\Draft as VersionDraft;
use App\States\VpsPlanVersion\Published as VersionPublished;
use App\States\VpsPlanVersion\Superseded as VersionSuperseded;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Service managing VPS plan version lifecycles.
 *
 * This service is the sole authorized writer to the vps_plan_versions table.
 */
final class VpsPlanVersionService
{
    public function __construct(
        private readonly HostCapacityGuard $capacityGuard = new HostCapacityGuard,
        private ?AuditLogger $auditLogger = null,
    ) {
        $this->auditLogger ??= app(AuditLogger::class);
    }

    /**
     * Create a new draft version for a plan or replace its existing draft version's profile.
     *
     * @param  array<string, mixed>  $profile
     *
     * @throws ValidationException
     */
    public function upsertDraft(VpsPlan $plan, array $profile, User $actor): VpsPlanVersion
    {
        $validated = $this->validateProfile($profile);

        return DB::transaction(function () use ($plan, $validated, $actor): VpsPlanVersion {
            /** @var VpsPlan $lockedPlan */
            $lockedPlan = VpsPlan::query()
                ->where('id', $plan->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var VpsPlanVersion|null $existingDraft */
            $existingDraft = VpsPlanVersion::query()
                ->where('vps_plan_id', $lockedPlan->id)
                ->where('status', VersionDraft::$name)
                ->lockForUpdate()
                ->first();

            $replacedExisting = $existingDraft !== null;

            if ($existingDraft !== null) {
                $existingDraft->update([
                    'cpu_cores' => $validated['cpu_cores'],
                    'ram_mb' => $validated['ram_mb'],
                    'disk_gb' => $validated['disk_gb'],
                    'instance_type' => $validated['instance_type'],
                    'net_download_mbps' => $validated['net_download_mbps'],
                    'net_upload_mbps' => $validated['net_upload_mbps'],
                    'change_summary' => $validated['change_summary'],
                ]);

                $version = $existingDraft->refresh();
            } else {
                /** @var VpsPlanVersion $version */
                $version = VpsPlanVersion::query()->create([
                    'vps_plan_id' => $lockedPlan->id,
                    'version' => null,
                    'status' => VersionDraft::class,
                    'cpu_cores' => $validated['cpu_cores'],
                    'ram_mb' => $validated['ram_mb'],
                    'disk_gb' => $validated['disk_gb'],
                    'instance_type' => $validated['instance_type'],
                    'net_download_mbps' => $validated['net_download_mbps'],
                    'net_upload_mbps' => $validated['net_upload_mbps'],
                    'change_summary' => $validated['change_summary'],
                    'created_by' => $actor->id,
                    'created_at' => Carbon::now(),
                ]);
            }

            $this->logger()->record(new AuditEntry(
                action: AuditAction::VpsPlanVersionCreated,
                outcome: AuditOutcome::Success,
                subject: $version,
                tenantUserId: null,
                visibility: 'admin',
                context: [
                    'vps_plan_id' => $lockedPlan->id,
                    'vps_plan_version_id' => $version->id,
                    'replaced_existing_draft' => $replacedExisting,
                    'cpu_cores' => $version->cpu_cores,
                    'ram_mb' => $version->ram_mb,
                    'disk_gb' => $version->disk_gb,
                    'instance_type' => $version->instance_type,
                    'net_download_mbps' => $version->net_download_mbps,
                    'net_upload_mbps' => $version->net_upload_mbps,
                ],
                actorType: 'user',
                actorId: $actor->id,
                actorLabel: $this->actorLabel($actor),
            ));

            VpsPlanVersionCreated::dispatch(
                $lockedPlan->id,
                $version->id,
                $version->cpu_cores,
                $version->ram_mb,
                $version->disk_gb,
                $version->instance_type,
                $version->net_download_mbps,
                $version->net_upload_mbps,
                $version->change_summary,
                $this->eventActor($actor),
            );

            return $version;
        });
    }

    /**
     * Discard the active draft version of a VPS plan without consuming a version number.
     */
    public function discardDraft(VpsPlan $plan, User $actor): void
    {
        DB::transaction(function () use ($plan, $actor): void {
            /** @var VpsPlan $lockedPlan */
            $lockedPlan = VpsPlan::query()
                ->where('id', $plan->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var VpsPlanVersion|null $draft */
            $draft = VpsPlanVersion::query()
                ->where('vps_plan_id', $lockedPlan->id)
                ->where('status', VersionDraft::$name)
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                throw new NotFoundHttpException('No draft version exists for this VPS plan.');
            }

            $draftId = $draft->id;
            $morphClass = $draft->getMorphClass();
            $draft->delete();

            $this->logger()->record(new AuditEntry(
                action: AuditAction::VpsPlanVersionDiscarded,
                outcome: AuditOutcome::Success,
                subject: ['type' => $morphClass, 'id' => (string) $draftId],
                tenantUserId: null,
                visibility: 'admin',
                context: [
                    'vps_plan_id' => $lockedPlan->id,
                    'vps_plan_version_id' => $draftId,
                ],
                actorType: 'user',
                actorId: $actor->id,
                actorLabel: $this->actorLabel($actor),
            ));
        });
    }

    /**
     * Publish the plan's draft version, superseding any previously published version.
     *
     * @throws ValidationException
     */
    public function publishDraft(VpsPlan $plan, User $actor): VpsPlanVersion
    {
        return DB::transaction(function () use ($plan, $actor): VpsPlanVersion {
            /** @var VpsPlan $lockedPlan */
            $lockedPlan = VpsPlan::query()
                ->where('id', $plan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPlan->status->equals(PlanArchived::class)) {
                throw new ConflictHttpException('Cannot publish a version of an archived VPS plan.');
            }

            /** @var VpsPlanVersion|null $draft */
            $draft = VpsPlanVersion::query()
                ->where('vps_plan_id', $lockedPlan->id)
                ->where('status', VersionDraft::$name)
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                throw ValidationException::withMessages([
                    'version' => ['NO_PUBLISHABLE_VERSION'],
                ]);
            }

            $this->assertPublishableProfile($draft);

            $maxVersion = VpsPlanVersion::query()
                ->where('vps_plan_id', $lockedPlan->id)
                ->whereNotNull('version')
                ->lockForUpdate()
                ->max('version');

            $nextVersion = ($maxVersion !== null ? Cast::int($maxVersion) : 0) + 1;

            /** @var VpsPlanVersion|null $previousPublished */
            $previousPublished = VpsPlanVersion::query()
                ->where('vps_plan_id', $lockedPlan->id)
                ->where('status', VersionPublished::$name)
                ->lockForUpdate()
                ->first();

            $supersededVersionId = null;
            if ($previousPublished !== null) {
                $supersededVersionId = $previousPublished->id;
                $previousPublished->status->transitionTo(VersionSuperseded::class);
            }

            $draft->version = $nextVersion;
            $draft->published_at = Carbon::now();
            $draft->published_by = $actor->id;
            $draft->status->transitionTo(VersionPublished::class);

            $lockedPlan->current_version_id = $draft->id;
            $lockedPlan->updated_by = $actor->id;
            $lockedPlan->save();

            $this->logger()->record(new AuditEntry(
                action: AuditAction::VpsPlanVersionPublished,
                outcome: AuditOutcome::Success,
                subject: $draft,
                tenantUserId: null,
                visibility: 'admin',
                context: [
                    'vps_plan_id' => $lockedPlan->id,
                    'vps_plan_version_id' => $draft->id,
                    'version' => $nextVersion,
                    'superseded_version_id' => $supersededVersionId,
                ],
                actorType: 'user',
                actorId: $actor->id,
                actorLabel: $this->actorLabel($actor),
            ));

            VpsPlanVersionPublished::dispatch(
                $lockedPlan->id,
                $draft->id,
                $nextVersion,
                $draft->cpu_cores,
                $draft->ram_mb,
                $draft->disk_gb,
                $draft->instance_type,
                $draft->net_download_mbps,
                $draft->net_upload_mbps,
                $supersededVersionId,
                $this->eventActor($actor),
            );

            return $draft->refresh();
        });
    }

    /**
     * Enforce Spec 7.3 host capacity and network-mode publish eligibility for a plan version profile.
     *
     * @throws ValidationException
     */
    public function assertPublishableProfile(VpsPlanVersion $version): void
    {
        $query = Host::query()
            ->where('is_active', true)
            ->where('status', HostOk::$name)
            ->where('capacity_source', '!=', 'unknown')
            ->where('total_cpu_cores', '>', 0)
            ->where('total_ram_mb', '>', 0)
            ->where('total_storage_gb', '>', 0);

        $isLocalOrTesting = app()->environment('local', 'testing') === true
            || in_array(config('app.env'), ['local', 'testing'], true)
            || (bool) config('app.allow_fake_driver');

        if (! $isLocalOrTesting) {
            $query->where('driver', '!=', 'fake');
        }

        $hasNetworkLimit = $version->net_download_mbps !== null || $version->net_upload_mbps !== null;
        $hasCoveringHost = false;

        /** @var Host $host */
        foreach ($query->get() as $host) {
            if (! $this->capacityGuard->hasKnownCapacity($host)) {
                continue;
            }

            $limit = $this->capacityGuard->getSingleInstancePhysicalLimit($host);
            $coversProfile = $version->cpu_cores <= $limit['cpu']
                && $version->ram_mb <= $limit['ram']
                && $version->disk_gb <= $limit['disk'];

            if (! $coversProfile) {
                continue;
            }

            $hasCoveringHost = true;

            if (! $hasNetworkLimit) {
                return;
            }

            $hasNonManualPool = IpPool::query()
                ->where('host_id', $host->id)
                ->where('active', true)
                ->where('network_mode', '!=', 'manual')
                ->exists();

            if ($hasNonManualPool) {
                return;
            }
        }

        if ($hasCoveringHost && $hasNetworkLimit) {
            throw ValidationException::withMessages([
                'net_download_mbps' => [
                    'At least one active healthy host with known capacity and an active non-manual IP pool is required when network bandwidth limits are set.',
                ],
            ]);
        }

        throw ValidationException::withMessages([
            'cpu_cores' => [
                'At least one active healthy host with known physical capacity is required to cover this plan profile.',
            ],
        ]);
    }

    /**
     * Validate a plan version resource profile against ResourceBounds.
     *
     * @param  array<string, mixed>  $profile
     * @return array{
     *     cpu_cores: int,
     *     ram_mb: int,
     *     disk_gb: int,
     *     instance_type: string,
     *     net_download_mbps: int|null,
     *     net_upload_mbps: int|null,
     *     change_summary: string|null,
     * }
     *
     * @throws ValidationException
     */
    private function validateProfile(array $profile): array
    {
        /** @var array<string, mixed> $validated */
        $validated = Validator::make($profile, [
            'cpu_cores' => ['required', 'integer', 'min:'.ResourceBounds::CPU_MIN, 'max:'.ResourceBounds::CPU_MAX],
            'ram_mb' => ['required', 'integer', 'min:'.ResourceBounds::RAM_MB_MIN, 'max:'.ResourceBounds::RAM_MB_MAX],
            'disk_gb' => ['required', 'integer', 'min:'.ResourceBounds::DISK_GB_MIN, 'max:'.ResourceBounds::DISK_GB_MAX],
            'instance_type' => ['required', 'string', Rule::in(ResourceBounds::INSTANCE_TYPES)],
            'net_download_mbps' => ['nullable', 'integer', 'min:'.ResourceBounds::NET_MBPS_MIN, 'max:'.ResourceBounds::NET_MBPS_MAX],
            'net_upload_mbps' => ['nullable', 'integer', 'min:'.ResourceBounds::NET_MBPS_MIN, 'max:'.ResourceBounds::NET_MBPS_MAX],
            'change_summary' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return [
            'cpu_cores' => Cast::int($validated['cpu_cores']),
            'ram_mb' => Cast::int($validated['ram_mb']),
            'disk_gb' => Cast::int($validated['disk_gb']),
            'instance_type' => Cast::string($validated['instance_type']),
            'net_download_mbps' => isset($validated['net_download_mbps']) ? Cast::int($validated['net_download_mbps']) : null,
            'net_upload_mbps' => isset($validated['net_upload_mbps']) ? Cast::int($validated['net_upload_mbps']) : null,
            'change_summary' => array_key_exists('change_summary', $validated)
                ? Cast::stringOrNull($validated['change_summary'])
                : null,
        ];
    }

    private function logger(): AuditLogger
    {
        return $this->auditLogger ??= app(AuditLogger::class);
    }

    private function actorLabel(User $actor): string
    {
        return $actor->email !== '' ? $actor->email : $actor->name;
    }

    /**
     * @return array{type: string, id: string|null, label: string|null}
     */
    private function eventActor(User $actor): array
    {
        $id = Cast::string($actor->getAuthIdentifier());
        $label = $this->actorLabel($actor);

        return [
            'type' => 'user',
            'id' => $id !== '' ? $id : null,
            'label' => $label !== '' ? $label : ($id !== '' ? "User #{$id}" : null),
        ];
    }
}
