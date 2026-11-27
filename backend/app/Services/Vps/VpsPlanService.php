<?php

declare(strict_types=1);

namespace App\Services\Vps;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Events\Vps\VpsPlanArchived;
use App\Events\Vps\VpsPlanCreated;
use App\Events\Vps\VpsPlanPublished;
use App\Events\Vps\VpsPlanUnarchived;
use App\Events\Vps\VpsPlanUnpublished;
use App\Events\Vps\VpsPlanUpdated;
use App\Models\User;
use App\Models\VpsPlan;
use App\Models\VpsPlanVersion;
use App\States\VpsPlan\Archived as PlanArchived;
use App\States\VpsPlan\Draft as PlanDraft;
use App\States\VpsPlan\Published as PlanPublished;
use App\Support\Cast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Service managing VPS catalog plans and their lifecycle state transitions.
 */
final class VpsPlanService
{
    public function __construct(
        private readonly VpsPlanVersionService $versionService,
        private ?AuditLogger $auditLogger = null,
    ) {
        $this->auditLogger ??= app(AuditLogger::class);
    }

    /**
     * Create a new VPS plan in draft status along with its initial draft version.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data, User $actor): VpsPlan
    {
        $slugCandidate = $data['slug'] ?? null;
        if (! is_string($slugCandidate) || trim($slugCandidate) === '') {
            $nameCandidate = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';
            $data['slug'] = Str::slug($nameCandidate);
        }

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:140', Rule::unique('vps_plans', 'slug')],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ])->validate();

        /** @var array<string, mixed> $profileInput */
        $profileInput = isset($data['profile']) && is_array($data['profile'])
            ? $data['profile']
            : $data;

        return DB::transaction(function () use ($validated, $profileInput, $actor): VpsPlan {
            /** @var VpsPlan $plan */
            $plan = VpsPlan::query()->create([
                'name' => Cast::string($validated['name']),
                'slug' => Cast::string($validated['slug']),
                'description' => array_key_exists('description', $validated)
                    ? Cast::stringOrNull($validated['description'])
                    : null,
                'status' => PlanDraft::class,
                'sort_order' => isset($validated['sort_order']) ? Cast::int($validated['sort_order']) : 0,
                'current_version_id' => null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $draftVersion = $this->versionService->upsertDraft($plan, $profileInput, $actor);

            $this->logger()->record(new AuditEntry(
                action: AuditAction::VpsPlanCreated,
                outcome: AuditOutcome::Success,
                subject: $plan,
                tenantUserId: null,
                visibility: 'admin',
                context: [
                    'vps_plan_id' => $plan->id,
                    'slug' => $plan->slug,
                    'name' => $plan->name,
                    'status' => $plan->status->getValue(),
                    'draft_version_id' => $draftVersion->id,
                ],
                actorType: 'user',
                actorId: $actor->id,
                actorLabel: $this->actorLabel($actor),
            ));

            VpsPlanCreated::dispatch(
                $plan->id,
                $plan->slug,
                $plan->name,
                $plan->status->getValue(),
                $draftVersion->id,
                $this->eventActor($actor),
            );

            return $plan->fresh(['draftVersion', 'currentVersion']) ?? $plan;
        });
    }

    /**
     * Update plan metadata, enforcing slug immutability once any version has been published.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function updateMetadata(VpsPlan $plan, array $data, User $actor): VpsPlan
    {
        return DB::transaction(function () use ($plan, $data, $actor): VpsPlan {
            /** @var VpsPlan $lockedPlan */
            $lockedPlan = VpsPlan::query()
                ->where('id', $plan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (array_key_exists('slug', $data) && $data['slug'] !== null && $data['slug'] !== $lockedPlan->slug) {
                $hasPublishedVersion = $lockedPlan->versions()->whereNotNull('version')->exists();
                if ($hasPublishedVersion) {
                    throw ValidationException::withMessages([
                        'slug' => ['Plan slug cannot be changed once a version has been published.'],
                    ]);
                }
            }

            /** @var array<string, mixed> $validated */
            $validated = Validator::make($data, [
                'name' => ['sometimes', 'required', 'string', 'max:120'],
                'slug' => ['sometimes', 'required', 'string', 'max:140', Rule::unique('vps_plans', 'slug')->ignore($lockedPlan->id)],
                'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'sort_order' => ['sometimes', 'integer', 'min:0'],
            ])->validate();

            if (array_key_exists('name', $validated)) {
                $lockedPlan->name = Cast::string($validated['name']);
            }
            if (array_key_exists('slug', $validated)) {
                $lockedPlan->slug = Cast::string($validated['slug']);
            }
            if (array_key_exists('description', $validated)) {
                $lockedPlan->description = Cast::stringOrNull($validated['description']);
            }
            if (array_key_exists('sort_order', $validated)) {
                $lockedPlan->sort_order = Cast::int($validated['sort_order']);
            }

            $lockedPlan->updated_by = $actor->id;
            $lockedPlan->save();

            $this->logger()->record(new AuditEntry(
                action: AuditAction::VpsPlanUpdated,
                outcome: AuditOutcome::Success,
                subject: $lockedPlan,
                tenantUserId: null,
                visibility: 'admin',
                context: [
                    'vps_plan_id' => $lockedPlan->id,
                    'slug' => $lockedPlan->slug,
                    'name' => $lockedPlan->name,
                    'sort_order' => $lockedPlan->sort_order,
                    'status' => $lockedPlan->status->getValue(),
                ],
                actorType: 'user',
                actorId: $actor->id,
                actorLabel: $this->actorLabel($actor),
            ));

            VpsPlanUpdated::dispatch(
                $lockedPlan->id,
                $lockedPlan->slug,
                $lockedPlan->name,
                $lockedPlan->sort_order,
                $lockedPlan->status->getValue(),
                $this->eventActor($actor),
            );

            return $lockedPlan->fresh(['draftVersion', 'currentVersion']) ?? $lockedPlan;
        });
    }

    /**
     * Publish a draft VPS plan, publishing its pending draft version or re-validating its current version.
     *
     * @throws ValidationException
     */
    public function publish(VpsPlan $plan, User $actor): VpsPlan
    {
        return DB::transaction(function () use ($plan, $actor): VpsPlan {
            /** @var VpsPlan $lockedPlan */
            $lockedPlan = VpsPlan::query()
                ->where('id', $plan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedPlan->status->equals(PlanDraft::class)) {
                throw new ConflictHttpException('Only draft VPS plans can be published.');
            }

            $hasDraft = $lockedPlan->draftVersion()->exists();
            if ($hasDraft) {
                $publishedVersion = $this->versionService->publishDraft($lockedPlan, $actor);
                $lockedPlan->refresh();
                $currentVersionId = $publishedVersion->id;
            } else {
                if ($lockedPlan->current_version_id === null) {
                    throw ValidationException::withMessages([
                        'version' => ['NO_PUBLISHABLE_VERSION'],
                    ]);
                }

                /** @var VpsPlanVersion|null $currentVersion */
                $currentVersion = VpsPlanVersion::query()->find($lockedPlan->current_version_id);
                if ($currentVersion === null) {
                    throw ValidationException::withMessages([
                        'version' => ['NO_PUBLISHABLE_VERSION'],
                    ]);
                }

                $this->versionService->assertPublishableProfile($currentVersion);
                $currentVersionId = $currentVersion->id;
            }

            $lockedPlan->updated_by = $actor->id;
            $lockedPlan->status->transitionTo(PlanPublished::class);

            $this->logger()->record(new AuditEntry(
                action: AuditAction::VpsPlanPublished,
                outcome: AuditOutcome::Success,
                subject: $lockedPlan,
                tenantUserId: null,
                visibility: 'admin',
                context: [
                    'vps_plan_id' => $lockedPlan->id,
                    'slug' => $lockedPlan->slug,
                    'current_version_id' => $currentVersionId,
                ],
                actorType: 'user',
                actorId: $actor->id,
                actorLabel: $this->actorLabel($actor),
            ));

            VpsPlanPublished::dispatch(
                $lockedPlan->id,
                $lockedPlan->slug,
                $lockedPlan->name,
                $currentVersionId,
                $this->eventActor($actor),
            );

            return $lockedPlan->fresh(['draftVersion', 'currentVersion']) ?? $lockedPlan;
        });
    }

    /**
     * Unpublish a published VPS plan back to draft status.
     */
    public function unpublish(VpsPlan $plan, User $actor): VpsPlan
    {
        return DB::transaction(function () use ($plan, $actor): VpsPlan {
            /** @var VpsPlan $lockedPlan */
            $lockedPlan = VpsPlan::query()
                ->where('id', $plan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedPlan->status->equals(PlanPublished::class)) {
                throw new ConflictHttpException('Only published VPS plans can be unpublished.');
            }

            $lockedPlan->updated_by = $actor->id;
            $lockedPlan->status->transitionTo(PlanDraft::class);

            $this->logger()->record(new AuditEntry(
                action: AuditAction::VpsPlanUnpublished,
                outcome: AuditOutcome::Success,
                subject: $lockedPlan,
                tenantUserId: null,
                visibility: 'admin',
                context: [
                    'vps_plan_id' => $lockedPlan->id,
                    'slug' => $lockedPlan->slug,
                ],
                actorType: 'user',
                actorId: $actor->id,
                actorLabel: $this->actorLabel($actor),
            ));

            VpsPlanUnpublished::dispatch(
                $lockedPlan->id,
                $lockedPlan->slug,
                $lockedPlan->name,
                $this->eventActor($actor),
            );

            return $lockedPlan->fresh(['draftVersion', 'currentVersion']) ?? $lockedPlan;
        });
    }

    /**
     * Archive a draft or published VPS plan.
     */
    public function archive(VpsPlan $plan, User $actor): VpsPlan
    {
        return DB::transaction(function () use ($plan, $actor): VpsPlan {
            /** @var VpsPlan $lockedPlan */
            $lockedPlan = VpsPlan::query()
                ->where('id', $plan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedPlan->status->canTransitionTo(PlanArchived::class)) {
                throw new ConflictHttpException('VPS plan is already archived.');
            }

            $previousStatus = $lockedPlan->status->getValue();
            $lockedPlan->updated_by = $actor->id;
            $lockedPlan->status->transitionTo(PlanArchived::class);

            $this->logger()->record(new AuditEntry(
                action: AuditAction::VpsPlanArchived,
                outcome: AuditOutcome::Success,
                subject: $lockedPlan,
                tenantUserId: null,
                visibility: 'admin',
                context: [
                    'vps_plan_id' => $lockedPlan->id,
                    'slug' => $lockedPlan->slug,
                    'previous_status' => $previousStatus,
                ],
                actorType: 'user',
                actorId: $actor->id,
                actorLabel: $this->actorLabel($actor),
            ));

            VpsPlanArchived::dispatch(
                $lockedPlan->id,
                $lockedPlan->slug,
                $lockedPlan->name,
                $this->eventActor($actor),
            );

            return $lockedPlan->fresh(['draftVersion', 'currentVersion']) ?? $lockedPlan;
        });
    }

    /**
     * Restore an archived VPS plan to draft status.
     */
    public function unarchive(VpsPlan $plan, User $actor): VpsPlan
    {
        return DB::transaction(function () use ($plan, $actor): VpsPlan {
            /** @var VpsPlan $lockedPlan */
            $lockedPlan = VpsPlan::query()
                ->where('id', $plan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedPlan->status->equals(PlanArchived::class)) {
                throw new ConflictHttpException('Only archived VPS plans can be unarchived.');
            }

            $lockedPlan->updated_by = $actor->id;
            $lockedPlan->status->transitionTo(PlanDraft::class);

            $this->logger()->record(new AuditEntry(
                action: AuditAction::VpsPlanUnarchived,
                outcome: AuditOutcome::Success,
                subject: $lockedPlan,
                tenantUserId: null,
                visibility: 'admin',
                context: [
                    'vps_plan_id' => $lockedPlan->id,
                    'slug' => $lockedPlan->slug,
                ],
                actorType: 'user',
                actorId: $actor->id,
                actorLabel: $this->actorLabel($actor),
            ));

            VpsPlanUnarchived::dispatch(
                $lockedPlan->id,
                $lockedPlan->slug,
                $lockedPlan->name,
                $this->eventActor($actor),
            );

            return $lockedPlan->fresh(['draftVersion', 'currentVersion']) ?? $lockedPlan;
        });
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
