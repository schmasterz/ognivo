<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\QuotaGroup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Models\VmSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Service calculating live tenant quota utilization and available headroom metrics.
 */
final class UserQuotaService
{
    /**
     * Check whether the given snapshot quota limit represents an unlimited (infinite) boundary.
     * Supports -1, null, and 0 semantics for infinite snapshot quotas.
     */
    public function isUnlimitedSnapshots(?int $maxSnapshots): bool
    {
        return $maxSnapshots === null || $maxSnapshots <= 0;
    }

    /**
     * Determine whether a user can create another snapshot for the given virtual machine.
     *
     * Accepts either (User $user, ?VirtualMachine $vm) or (VirtualMachine $vm, ?User $user).
     */
    public function canCreateSnapshot(User|VirtualMachine $userOrVm, VirtualMachine|User|null $vmOrUser = null): bool
    {
        [$user, $vm] = $this->resolveUserAndVm($userOrVm, $vmOrUser);

        $quota = $this->resolveUserQuota($user);
        $maxSnapshots = $quota->max_snapshots;

        if ($this->isUnlimitedSnapshots($maxSnapshots)) {
            return true;
        }

        $activeSnapshots = $this->countActiveSnapshots($user, $vm);
        $effectiveLimit = $this->effectiveSnapshotLimit($user, (int) $maxSnapshots, $vm);

        return $activeSnapshots < $effectiveLimit;
    }

    /**
     * Calculate remaining snapshot slots available for the given user / virtual machine (-1 when unlimited).
     */
    public function remainingSnapshots(User|VirtualMachine $userOrVm, VirtualMachine|User|null $vmOrUser = null): int
    {
        [$user, $vm] = $this->resolveUserAndVm($userOrVm, $vmOrUser);

        $quota = $this->resolveUserQuota($user);
        $maxSnapshots = $quota->max_snapshots;

        if ($this->isUnlimitedSnapshots($maxSnapshots)) {
            return -1;
        }

        $activeSnapshots = $this->countActiveSnapshots($user, $vm);
        $effectiveLimit = $this->effectiveSnapshotLimit($user, (int) $maxSnapshots, $vm);

        return max(0, $effectiveLimit - $activeSnapshots);
    }

    /**
     * Assert that the user can create another snapshot for the given virtual machine, throwing a ValidationException if exceeded.
     *
     * @throws ValidationException
     */
    public function assertCanCreateSnapshot(User|VirtualMachine $userOrVm, VirtualMachine|User|null $vmOrUser = null): void
    {
        if (! $this->canCreateSnapshot($userOrVm, $vmOrUser)) {
            throw ValidationException::withMessages([
                'quota' => [__('messages.quota.snapshots_exceeded')],
            ]);
        }
    }

    /**
     * Count active (non-failed, non-deleting) snapshots for a virtual machine or user.
     */
    public function countActiveSnapshots(User $user, ?VirtualMachine $vm = null): int
    {
        $query = VmSnapshot::query()
            ->whereNotIn('status', [VmSnapshot::STATUS_FAILED, VmSnapshot::STATUS_DELETING]);

        if ($vm !== null) {
            $query->where('virtual_machine_id', $vm->id);
        } else {
            $query->where('user_id', $user->id);
        }

        return $query->count();
    }

    /**
     * Compute the effective snapshot limit for a single VM or across a user's active VMs.
     */
    private function effectiveSnapshotLimit(User $user, int $maxSnapshots, ?VirtualMachine $vm = null, ?int $activeVmCount = null): int
    {
        if ($vm !== null) {
            return $maxSnapshots;
        }

        $vmCount = $activeVmCount ?? VirtualMachine::where('user_id', $user->id)
            ->whereNotIn('status', ['failed', 'deleted'])
            ->count();

        return max(1, $vmCount) * $maxSnapshots;
    }

    /**
     * Resolve User and optional VirtualMachine from flexible argument ordering.
     *
     * @return array{0: User, 1: VirtualMachine|null}
     */
    private function resolveUserAndVm(User|VirtualMachine $first, VirtualMachine|User|null $second = null): array
    {
        if ($first instanceof VirtualMachine) {
            $vm = $first;
            /** @var User $user */
            $user = $second instanceof User ? $second : ($vm->user ?? User::findOrFail($vm->user_id));

            return [$user, $vm];
        }

        return [$first, $second instanceof VirtualMachine ? $second : null];
    }

    /**
     * Retrieve or initialize the UserQuota record for the target user.
     */
    public function resolveUserQuota(User $targetUser): UserQuota
    {
        $existing = UserQuota::where('user_id', $targetUser->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        if ($targetUser->relationLoaded('quota') && $targetUser->quota !== null) {
            return $targetUser->quota;
        }

        $defaultGroup = QuotaGroup::getDefaultGroup();

        /** @var UserQuota $quota */
        $quota = UserQuota::firstOrCreate(
            ['user_id' => $targetUser->id],
            array_merge(
                ['quota_group_id' => $targetUser->quota_group_id ?? $defaultGroup?->id],
                SystemSetting::getDefaultQuota()
            )
        );

        return $quota;
    }

    /**
     * Compute quota boundaries, active consumption, and available resource margins.
     *
     * @param  array{host_id?: int|null, status?: string|null, search?: string|null, vm_id?: int|null}  $filters
     * @return array{
     *     user: array{id: int, name: string, email: string},
     *     quota: UserQuota,
     *     usage: array{vms: int, cpu_cores: int, ram_mb: int, disk_gb: int, snapshots: int},
     *     available: array{vms: int, cpu_cores: int, ram_mb: int, disk_gb: int, snapshots: int},
     *     filters: array{host_id: int|null, status: string|null, search: string|null}
     * }
     */
    public function getMetrics(User $targetUser, array $filters = []): array
    {
        $defaultGroup = QuotaGroup::getDefaultGroup();

        /** @var UserQuota $quota */
        $quota = UserQuota::firstOrCreate(
            ['user_id' => $targetUser->id],
            array_merge(
                ['quota_group_id' => $targetUser->quota_group_id ?? $defaultGroup?->id],
                SystemSetting::getDefaultQuota()
            )
        );
        $quota->load('quotaGroup');

        /** @var Builder<VirtualMachine> $query */
        $query = VirtualMachine::where('user_id', $targetUser->id)
            ->whereNotIn('status', ['failed', 'deleted']);

        if (isset($filters['host_id'])) {
            $query->where('host_id', $filters['host_id']);
        }

        if (isset($filters['vm_id'])) {
            $query->where('id', $filters['vm_id']);
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $status = $filters['status'];
            if (in_array($status, ['active', 'inactive', 'pending'], true)) {
                $query->whereHas('user', fn (Builder $uq): Builder => $uq->where('status', $status));
            } else {
                $query->where('status', $status);
            }
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('incus_project', 'like', "%{$search}%")
                    ->orWhere('ipv4_address', 'like', "%{$search}%")
                    ->orWhereHas('user', function (Builder $uq) use ($search): void {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        /** @var object{cpu: int|float|string, ram: int|float|string, disk: int|float|string, vms: int|string} $usage */
        $usage = (clone $query)->selectRaw('COALESCE(SUM(cpu_cores), 0) as cpu,
                                    COALESCE(SUM(ram_mb), 0) as ram,
                                    COALESCE(SUM(disk_gb), 0) as disk,
                                    COUNT(*) as vms')
            ->firstOrFail();

        $vmsUsed = (int) $usage->vms;
        $cpuUsed = (int) $usage->cpu;
        $ramUsed = (int) $usage->ram;
        $diskUsed = (int) $usage->disk;

        $hasVmFilters = isset($filters['host_id'])
            || isset($filters['vm_id'])
            || (isset($filters['status']) && $filters['status'] !== '')
            || (isset($filters['search']) && $filters['search'] !== '');

        $snapshotsUsed = $hasVmFilters
            ? (int) VmSnapshot::query()
                ->where('user_id', $targetUser->id)
                ->whereNotIn('status', [VmSnapshot::STATUS_FAILED, VmSnapshot::STATUS_DELETING])
                ->whereIn('virtual_machine_id', (clone $query)->select('id'))
                ->count()
            : $this->countActiveSnapshots($targetUser);

        $maxVms = (int) $quota->max_vms;
        $maxCpu = (int) $quota->max_cpu_cores;
        $maxRam = (int) $quota->max_ram_mb;
        $maxStorage = (int) $quota->max_storage_gb;
        $maxSnapshots = $quota->max_snapshots;
        $effectiveMaxSnapshots = isset($filters['vm_id'])
            ? (int) $maxSnapshots
            : $this->effectiveSnapshotLimit($targetUser, (int) $maxSnapshots, null, $vmsUsed);
        $snapshotsAvailable = $this->isUnlimitedSnapshots($maxSnapshots)
            ? -1
            : max(0, $effectiveMaxSnapshots - $snapshotsUsed);

        return [
            'user' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
            ],
            'quota' => $quota,
            'usage' => [
                'vms' => $vmsUsed,
                'cpu_cores' => $cpuUsed,
                'ram_mb' => $ramUsed,
                'disk_gb' => $diskUsed,
                'snapshots' => $snapshotsUsed,
            ],
            'available' => [
                'vms' => $maxVms === -1 ? -1 : max(0, $maxVms - $vmsUsed),
                'cpu_cores' => $maxCpu === -1 ? -1 : max(0, $maxCpu - $cpuUsed),
                'ram_mb' => $maxRam === -1 ? -1 : max(0, $maxRam - $ramUsed),
                'disk_gb' => $maxStorage === -1 ? -1 : max(0, $maxStorage - $diskUsed),
                'snapshots' => $snapshotsAvailable,
            ],
            'filters' => [
                'host_id' => $filters['host_id'] ?? null,
                'status' => isset($filters['status']) && $filters['status'] !== '' ? $filters['status'] : null,
                'search' => isset($filters['search']) && $filters['search'] !== '' ? $filters['search'] : null,
            ],
        ];
    }
}
