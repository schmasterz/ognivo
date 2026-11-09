<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\DTO\Quota\CreateQuotaGroupDTO;
use App\DTO\Quota\DefaultQuotaDTO;
use App\DTO\Quota\UpdateQuotaGroupDTO;
use App\Models\QuotaGroup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserQuota;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service managing resource quota tiers and platform default quota policies.
 */
final class QuotaGroupService
{
    public function __construct(
        private ?AuditLogger $auditLogger = null,
    ) {
        $this->auditLogger ??= app(AuditLogger::class);
    }

    private function logger(): AuditLogger
    {
        return $this->auditLogger ??= app(AuditLogger::class);
    }

    /**
     * List all quota groups ordered by default status and name.
     *
     * @return Collection<int, QuotaGroup>
     */
    public function list(): Collection
    {
        return QuotaGroup::withCount('users')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    /**
     * Paginate quota groups ordered by default status and name.
     *
     * @return LengthAwarePaginator<int, QuotaGroup>
     */
    public function paginate(int $perPage = 10): LengthAwarePaginator
    {
        return QuotaGroup::withCount('users')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * Create a new Quota Group.
     */
    public function create(CreateQuotaGroupDTO $dto): QuotaGroup
    {
        return DB::transaction(function () use ($dto): QuotaGroup {
            if ($dto->isDefault) {
                QuotaGroup::where('is_default', true)->update(['is_default' => false]);
            }

            /** @var QuotaGroup $group */
            $group = QuotaGroup::create([
                'name' => $dto->name,
                'description' => $dto->description,
                'is_default' => $dto->isDefault,
                'max_vms' => $dto->maxVms,
                'max_cpu_cores' => $dto->maxCpuCores,
                'max_ram_mb' => $dto->maxRamMb,
                'max_storage_gb' => $dto->maxStorageGb,
                'max_ips' => $dto->maxIps,
                'max_snapshots' => $dto->maxSnapshots,
            ]);

            $this->logger()->record(new AuditEntry(
                action: AuditAction::QuotaGroupCreated,
                outcome: AuditOutcome::Success,
                subject: $group,
                tenantUserId: null,
                visibility: 'admin',
                context: [
                    'name' => $group->name,
                    'is_default' => $group->is_default,
                    'max_vms' => $group->max_vms,
                    'max_cpu_cores' => $group->max_cpu_cores,
                    'max_ram_mb' => $group->max_ram_mb,
                    'max_storage_gb' => $group->max_storage_gb,
                    'max_ips' => $group->max_ips,
                    'max_snapshots' => $group->max_snapshots,
                ],
            ));

            return $group->fresh() ?? $group;
        });
    }

    /**
     * Update an existing Quota Group and synchronize limits to all assigned users.
     */
    public function update(QuotaGroup $quotaGroup, UpdateQuotaGroupDTO $dto): QuotaGroup
    {
        return DB::transaction(function () use ($quotaGroup, $dto): QuotaGroup {
            if ($dto->isDefault && ! $quotaGroup->is_default) {
                QuotaGroup::where('id', '!=', $quotaGroup->id)->update(['is_default' => false]);
            }

            $maxSnapshots = $dto->maxSnapshots ?? $quotaGroup->max_snapshots ?? 3;

            $quotaGroup->update([
                'name' => $dto->name,
                'description' => $dto->description,
                'is_default' => $dto->isDefault,
                'max_vms' => $dto->maxVms,
                'max_cpu_cores' => $dto->maxCpuCores,
                'max_ram_mb' => $dto->maxRamMb,
                'max_storage_gb' => $dto->maxStorageGb,
                'max_ips' => $dto->maxIps,
                'max_snapshots' => $maxSnapshots,
            ]);

            UserQuota::where('quota_group_id', $quotaGroup->id)->update([
                'max_vms' => $dto->maxVms,
                'max_cpu_cores' => $dto->maxCpuCores,
                'max_ram_mb' => $dto->maxRamMb,
                'max_storage_gb' => $dto->maxStorageGb,
                'max_ips' => $dto->maxIps,
                'max_snapshots' => $maxSnapshots,
            ]);

            $quotaGroup->refresh()->loadCount('users');

            return $quotaGroup;
        });
    }

    /**
     * Delete a Quota Group and reassign users to the system default group.
     */
    public function delete(QuotaGroup $quotaGroup): void
    {
        if ($quotaGroup->is_default) {
            $msg = __('messages.admin.cannot_delete_default_group');
            abort(422, is_string($msg) ? $msg : 'Cannot delete the default quota group.');
        }

        DB::transaction(function () use ($quotaGroup): void {
            /** @var QuotaGroup|null $defaultGroup */
            $defaultGroup = QuotaGroup::getDefaultGroup();
            if ($defaultGroup !== null && $defaultGroup->id !== $quotaGroup->id) {
                User::where('quota_group_id', $quotaGroup->id)->update(['quota_group_id' => $defaultGroup->id]);
                UserQuota::where('quota_group_id', $quotaGroup->id)->update([
                    'quota_group_id' => $defaultGroup->id,
                    'max_vms' => $defaultGroup->max_vms,
                    'max_cpu_cores' => $defaultGroup->max_cpu_cores,
                    'max_ram_mb' => $defaultGroup->max_ram_mb,
                    'max_storage_gb' => $defaultGroup->max_storage_gb,
                    'max_ips' => $defaultGroup->max_ips,
                    'max_snapshots' => $defaultGroup->max_snapshots ?? 3,
                ]);
            }

            $quotaGroup->delete();
        });
    }

    /**
     * @return array{max_vms: int, max_cpu_cores: int, max_ram_mb: int, max_storage_gb: int, max_ips: int, max_snapshots: int}
     */
    public function getDefaultQuota(): array
    {
        return SystemSetting::getDefaultQuota();
    }

    /**
     * Update system-wide default quota and the default group if one exists.
     *
     * @return array{max_vms: int, max_cpu_cores: int, max_ram_mb: int, max_storage_gb: int, max_ips: int, max_snapshots: int}
     */
    public function updateDefaultQuota(DefaultQuotaDTO $dto): array
    {
        $payload = $dto->toArray();

        SystemSetting::updateOrCreate(
            ['key' => 'default_quota'],
            ['value' => $payload]
        );

        $defaultGroup = QuotaGroup::getDefaultGroup();
        if ($defaultGroup !== null) {
            $defaultGroup->update([
                'max_vms' => $dto->maxVms,
                'max_cpu_cores' => $dto->maxCpuCores,
                'max_ram_mb' => $dto->maxRamMb,
                'max_storage_gb' => $dto->maxStorageGb,
                'max_ips' => $dto->maxIps,
                'max_snapshots' => $dto->maxSnapshots,
            ]);
        }

        $this->logger()->record(new AuditEntry(
            action: AuditAction::QuotaDefaultUpdated,
            outcome: AuditOutcome::Success,
            subject: ['type' => 'SystemSetting', 'id' => 'default_quota'],
            tenantUserId: null,
            visibility: 'admin',
            context: [
                'default_quota' => $payload,
            ],
        ));

        return $payload;
    }
}
