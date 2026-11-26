<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\DTO\User\CreateUserDTO;
use App\DTO\User\UpdateUserDTO;
use App\Jobs\DeleteUserWorkloadsJob;
use App\Jobs\SuspendUserWorkloadsJob;
use App\Models\QuotaGroup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserQuota;
use App\Services\Hypervisor\HypervisorManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

/**
 * Service encapsulating tenant user account lifecycle, role administration, and quota enforcement.
 */
final class UserService
{
    public function __construct(
        private readonly HypervisorManager $hypervisorManager,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?AuthService $authService = null,
    ) {}

    private function logger(): AuditLogger
    {
        return $this->auditLogger ?? app(AuditLogger::class);
    }

    private function auth(): AuthService
    {
        return $this->authService ?? app(AuthService::class);
    }

    /**
     * Build the query for tenant users with workload counts and quota relations, optionally filtered.
     *
     * @param  array{user_id?: int|null, host_id?: int|null, status?: string|null, search?: string|null}  $filters
     * @return Builder<User>
     */
    private function buildUsersQuery(array $filters = []): Builder
    {
        /** @var Builder<User> $query */
        $query = User::with(['roles:id,name', 'quota', 'quotaGroup'])
            ->withCount(['virtualMachines', 'ipAllocations']);

        if (isset($filters['user_id'])) {
            $query->where('id', $filters['user_id']);
        }

        if (isset($filters['host_id'])) {
            $hostId = $filters['host_id'];
            $query->where(function (Builder $q) use ($hostId): void {
                $q->whereHas('virtualMachines', fn (Builder $vq): Builder => $vq->where('host_id', $hostId)->whereNotIn('status', ['deleted', 'failed']))
                    ->orWhereHas('ipAllocations', fn (Builder $iq): Builder => $iq->whereHas('pool', fn (Builder $pq): Builder => $pq->where('host_id', $hostId)));
            });
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $status = $filters['status'];
            if (in_array($status, ['active', 'suspended', 'inactive', 'pending', 'deleting', 'deletion_failed'], true)) {
                $query->where('status', $status);
            } else {
                $query->whereHas('virtualMachines', fn (Builder $vq): Builder => $vq->where('status', $status));
            }
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search): void {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('virtualMachines', function (Builder $vq) use ($search): void {
                        $vq->where('ipv4_address', 'like', "%{$search}%")
                            ->orWhere('ipv6_address', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('ipAllocations', fn (Builder $iq): Builder => $iq->where('ip_string', 'like', "%{$search}%"));
            });
        }

        return $query->orderBy('email');
    }

    /**
     * List all tenant users with workload counts and quota relations, optionally filtered.
     *
     * @param  array{user_id?: int|null, host_id?: int|null, status?: string|null, search?: string|null}  $filters
     * @return Collection<int, User>
     */
    public function listAll(array $filters = []): Collection
    {
        return $this->buildUsersQuery($filters)->get();
    }

    /**
     * Paginate tenant users with workload counts and quota relations, optionally filtered.
     *
     * @param  array{user_id?: int|null, host_id?: int|null, status?: string|null, search?: string|null}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginateAll(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->buildUsersQuery($filters)->paginate($perPage);
    }

    /**
     * Create a new tenant user with associated role and quota specifications.
     */
    public function create(CreateUserDTO $dto): User
    {
        return DB::transaction(function () use ($dto): User {
            /** @var QuotaGroup|null $targetGroup */
            $targetGroup = null;
            if ($dto->quotaGroupId !== null) {
                $targetGroup = QuotaGroup::find($dto->quotaGroupId);
            }

            /** @var User $user */
            $user = User::create([
                'name' => $dto->name,
                'email' => $dto->email,
                'status' => $dto->status,
                ...$this->auth()->generateCredentials($dto->password),
                'quota_group_id' => $targetGroup?->id,
            ]);

            $role = Role::firstOrCreate(['name' => $dto->role, 'guard_name' => 'web']);
            $user->assignRole($role);

            if ($targetGroup !== null) {
                UserQuota::create([
                    'user_id' => $user->id,
                    'quota_group_id' => $targetGroup->id,
                    'max_vms' => $targetGroup->max_vms,
                    'max_cpu_cores' => $targetGroup->max_cpu_cores,
                    'max_ram_mb' => $targetGroup->max_ram_mb,
                    'max_storage_gb' => $targetGroup->max_storage_gb,
                    'max_ips' => $targetGroup->max_ips,
                    'max_snapshots' => $targetGroup->max_snapshots ?? 3,
                ]);
            } else {
                $customQuota = $dto->quota?->toArray() ?? [];
                $defaultQuota = SystemSetting::getDefaultQuota();

                UserQuota::create([
                    'user_id' => $user->id,
                    'quota_group_id' => null,
                    'max_vms' => $customQuota['max_vms'] ?? $defaultQuota['max_vms'],
                    'max_cpu_cores' => $customQuota['max_cpu_cores'] ?? $defaultQuota['max_cpu_cores'],
                    'max_ram_mb' => $customQuota['max_ram_mb'] ?? $defaultQuota['max_ram_mb'],
                    'max_storage_gb' => $customQuota['max_storage_gb'] ?? $defaultQuota['max_storage_gb'],
                    'max_ips' => $customQuota['max_ips'] ?? $defaultQuota['max_ips'],
                    'max_snapshots' => $customQuota['max_snapshots'] ?? $defaultQuota['max_snapshots'],
                ]);
            }

            $this->logger()->record(new AuditEntry(
                action: AuditAction::UserCreated,
                outcome: AuditOutcome::Success,
                subject: $user,
                tenantUserId: $user->id,
                visibility: 'tenant',
                context: [
                    'role' => $dto->role,
                    'status' => $dto->status,
                    'quota_group_id' => $user->quota_group_id,
                ],
                pii: [
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ));

            return $user->fresh(['roles', 'quota', 'quotaGroup']) ?? $user;
        });
    }

    /**
     * Update an existing user account and synchronize role, status, or quota boundaries.
     */
    public function update(User $user, UpdateUserDTO $dto, ?User $admin = null): User
    {
        $actingAdmin = $admin ?? (auth()->user() instanceof User ? auth()->user() : null);

        // Guard 1: An admin cannot change their own role
        if ($actingAdmin !== null && $actingAdmin->id === $user->id && ! $user->hasRole($dto->role)) {
            $msg = __('messages.admin.cannot_demote_self');
            abort(422, is_string($msg) ? $msg : 'You cannot change your own role.');
        }

        // Guard 2: An admin cannot remove the admin role from the last remaining active admin
        if ($user->hasRole('admin') && $dto->role !== 'admin') {
            $activeAdminsCount = User::role('admin')->where('status', 'active')->count();
            if ($user->status === 'active' && $activeAdminsCount <= 1) {
                $msg = __('messages.admin.cannot_demote_last_admin');
                abort(422, is_string($msg) ? $msg : 'Cannot remove the administrator role from the last remaining active administrator.');
            }
        }

        $firstRole = $user->roles->first();
        /** @var string|null $oldRole */
        $oldRole = $firstRole !== null && isset($firstRole->name) && is_string($firstRole->name) ? $firstRole->name : null;
        $oldStatus = $user->status;

        return DB::transaction(function () use ($user, $dto, $oldRole, $oldStatus): User {
            $roleChanged = ! $user->hasRole($dto->role);
            $passwordChanged = $dto->password !== null && $dto->password !== '';
            $statusChanged = $dto->status !== null && $dto->status !== $oldStatus;
            $statusChangedToNonActive = $dto->status !== null && $dto->status !== 'active';

            if ($roleChanged) {
                $user->originalRole = $oldRole;
                $user->syncRoles([$dto->role]);
            }

            $user->name = $dto->name;
            $user->email = $dto->email;

            if ($dto->status !== null) {
                $user->status = $dto->status;
            }

            if ($dto->hasQuotaGroupId) {
                $user->quota_group_id = $dto->quotaGroupId;
            }

            if ($dto->password !== null && $dto->password !== '') {
                $this->auth()->applyPassword($user, $dto->password);
            }
            $user->save();

            if (! $roleChanged) {
                $user->syncRoles([$dto->role]);
            }

            if ($roleChanged) {
                $this->logger()->record(new AuditEntry(
                    action: AuditAction::UserRoleChanged,
                    outcome: AuditOutcome::Success,
                    subject: $user,
                    tenantUserId: $user->id,
                    visibility: 'tenant',
                    context: [
                        'old_role' => $oldRole,
                        'new_role' => $dto->role,
                    ],
                ));
            }

            if ($statusChanged) {
                $this->logger()->record(new AuditEntry(
                    action: AuditAction::UserStatusChanged,
                    outcome: AuditOutcome::Success,
                    subject: $user,
                    tenantUserId: $user->id,
                    visibility: 'tenant',
                    context: [
                        'old_status' => $oldStatus,
                        'new_status' => $dto->status,
                    ],
                ));
            }

            // Revoke all tokens when status changes to anything other than active, password changed, or roles change
            if ($statusChangedToNonActive || $passwordChanged || $roleChanged) {
                $user->tokens()->delete();
                $reason = $statusChangedToNonActive ? 'status' : ($passwordChanged ? 'password' : 'role');
                $this->logger()->record(new AuditEntry(
                    action: AuditAction::AuthTokensRevoked,
                    outcome: AuditOutcome::Success,
                    subject: $user,
                    tenantUserId: $user->id,
                    visibility: 'tenant',
                    context: ['reason' => $reason],
                ));
            }

            /** @var QuotaGroup|null $targetGroup */
            $targetGroup = $user->quota_group_id !== null ? QuotaGroup::find($user->quota_group_id) : null;

            if ($targetGroup !== null) {
                $user->quota()->updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'quota_group_id' => $targetGroup->id,
                        'max_vms' => $targetGroup->max_vms,
                        'max_cpu_cores' => $targetGroup->max_cpu_cores,
                        'max_ram_mb' => $targetGroup->max_ram_mb,
                        'max_storage_gb' => $targetGroup->max_storage_gb,
                        'max_ips' => $targetGroup->max_ips,
                        'max_snapshots' => $targetGroup->max_snapshots ?? 3,
                    ]
                );
            } else {
                $customQuota = $dto->quota?->toArray() ?? [];
                $defaultQuota = SystemSetting::getDefaultQuota();

                $userQuota = $user->quota;
                $quotaData = [
                    'quota_group_id' => null,
                    'max_vms' => $customQuota['max_vms'] ?? ($userQuota !== null ? $userQuota->max_vms : $defaultQuota['max_vms']),
                    'max_cpu_cores' => $customQuota['max_cpu_cores'] ?? ($userQuota !== null ? $userQuota->max_cpu_cores : $defaultQuota['max_cpu_cores']),
                    'max_ram_mb' => $customQuota['max_ram_mb'] ?? ($userQuota !== null ? $userQuota->max_ram_mb : $defaultQuota['max_ram_mb']),
                    'max_storage_gb' => $customQuota['max_storage_gb'] ?? ($userQuota !== null ? $userQuota->max_storage_gb : $defaultQuota['max_storage_gb']),
                    'max_ips' => $customQuota['max_ips'] ?? ($userQuota !== null ? $userQuota->max_ips : $defaultQuota['max_ips']),
                    'max_snapshots' => $customQuota['max_snapshots'] ?? ($userQuota !== null && $userQuota->max_snapshots !== null ? $userQuota->max_snapshots : $defaultQuota['max_snapshots']),
                ];

                $user->quota()->updateOrCreate(['user_id' => $user->id], $quotaData);
            }

            return $user->fresh(['roles', 'quota', 'quotaGroup']) ?? $user;
        });

        if ($user->status === 'suspended') {
            $this->suspendUserWorkloads($user);
        }

        return $user;
    }

    /**
     * Update only the status of a user account and suspend workloads if suspended.
     */
    public function updateStatus(User $admin, User $user, string $status): User
    {
        if ($admin->id === $user->id && $status !== 'active') {
            $msg = __('messages.admin.cannot_suspend_self');
            abort(422, is_string($msg) ? $msg : 'Cannot suspend your own account.');
        }

        if ($user->hasRole('admin') && $user->status === 'active' && $status !== 'active') {
            $activeAdminsCount = User::role('admin')->where('status', 'active')->count();
            if ($activeAdminsCount <= 1) {
                $msg = __('messages.admin.cannot_demote_last_admin');
                abort(422, is_string($msg) ? $msg : 'Cannot deactivate the last remaining active administrator.');
            }
        }

        $oldStatus = $user->status;

        $updatedUser = DB::transaction(function () use ($user, $status, $oldStatus): User {
            $user->status = $status;
            $user->save();

            if ($status !== $oldStatus) {
                $this->logger()->record(new AuditEntry(
                    action: AuditAction::UserStatusChanged,
                    outcome: AuditOutcome::Success,
                    subject: $user,
                    tenantUserId: $user->id,
                    visibility: 'tenant',
                    context: [
                        'old_status' => $oldStatus,
                        'new_status' => $status,
                    ],
                ));
            }

            if ($status !== 'active') {
                $user->tokens()->delete();
                $this->logger()->record(new AuditEntry(
                    action: AuditAction::AuthTokensRevoked,
                    outcome: AuditOutcome::Success,
                    subject: $user,
                    tenantUserId: $user->id,
                    visibility: 'tenant',
                    context: ['reason' => 'status'],
                ));
            }

            return $user->fresh(['roles', 'quota', 'quotaGroup']) ?? $user;
        });

        if ($status === 'suspended') {
            $this->suspendUserWorkloads($updatedUser);
        }

        return $updatedUser;
    }

    /**
     * Suspend all active virtual machines owned by the given user asynchronously.
     */
    public function suspendUserWorkloads(User $user): void
    {
        if ($user->status !== 'suspended') {
            DB::transaction(function () use ($user): void {
                $oldStatus = $user->status;
                $user->status = 'suspended';
                $user->save();

                $this->logger()->record(new AuditEntry(
                    action: AuditAction::UserStatusChanged,
                    outcome: AuditOutcome::Success,
                    subject: $user,
                    tenantUserId: $user->id,
                    visibility: 'tenant',
                    context: [
                        'old_status' => $oldStatus,
                        'new_status' => 'suspended',
                    ],
                ));

                $user->tokens()->delete();
                $this->logger()->record(new AuditEntry(
                    action: AuditAction::AuthTokensRevoked,
                    outcome: AuditOutcome::Success,
                    subject: $user,
                    tenantUserId: $user->id,
                    visibility: 'tenant',
                    context: ['reason' => 'status'],
                ));
            });
        }

        try {
            SuspendUserWorkloadsJob::dispatch($user);
        } catch (\Throwable $e) {
            Log::error(sprintf('Error dispatching SuspendUserWorkloadsJob for user #%d: %s', $user->id, $e->getMessage()));
        }
    }

    /**
     * Delete a tenant user and safely unbind associated cloud assets.
     */
    public function delete(User $admin, User $user): void
    {
        $auditContext = app(AuditContext::class);
        $isImpersonated = $auditContext->getImpersonatorId() !== null;

        if ($isImpersonated) {
            $this->logger()->recordIndependent(new AuditEntry(
                action: AuditAction::UserDeleted,
                outcome: AuditOutcome::Denied,
                subject: $user,
                tenantUserId: $user->id,
                impersonatorId: $auditContext->getImpersonatorId(),
                visibility: 'tenant',
                context: [
                    'reason' => 'forbidden_while_impersonating',
                ],
            ));

            abort(403, 'Deleting accounts is forbidden while impersonating.');
        }

        if ($admin->id === $user->id) {
            $msg = __('messages.admin.cannot_delete_self');
            abort(422, is_string($msg) ? $msg : 'Cannot delete self.');
        }

        if ($user->hasRole('admin') && $user->status === 'active') {
            $activeAdminsCount = User::role('admin')->where('status', 'active')->count();
            if ($activeAdminsCount <= 1) {
                $msg = __('messages.admin.cannot_demote_last_admin');
                abort(422, is_string($msg) ? $msg : 'Cannot delete the last remaining active administrator.');
            }
        }

        DB::transaction(function () use ($user): void {
            $user->status = 'deleting';
            $user->save();
            $user->tokens()->delete();

            $this->logger()->record(new AuditEntry(
                action: AuditAction::AuthTokensRevoked,
                outcome: AuditOutcome::Success,
                subject: $user,
                tenantUserId: $user->id,
                visibility: 'tenant',
                context: ['reason' => 'status'],
            ));

            $this->logger()->record(new AuditEntry(
                action: AuditAction::UserDeleted,
                outcome: AuditOutcome::Success,
                subject: $user,
                tenantUserId: $user->id,
                visibility: 'tenant',
                pii: [
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ));
        });

        try {
            DeleteUserWorkloadsJob::dispatch($user);
        } catch (\Throwable $e) {
            Log::error(sprintf('Error dispatching DeleteUserWorkloadsJob for user #%d: %s', $user->id, $e->getMessage()));
        }
    }

    /**
     * Assign a specific quota group to a tenant user.
     */
    public function assignQuotaGroup(User $user, int $quotaGroupId): QuotaGroup
    {
        /** @var QuotaGroup $group */
        $group = QuotaGroup::findOrFail($quotaGroupId);

        DB::transaction(function () use ($user, $group): void {
            $user->update(['quota_group_id' => $group->id]);

            $user->quota()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'quota_group_id' => $group->id,
                    'max_vms' => $group->max_vms,
                    'max_cpu_cores' => $group->max_cpu_cores,
                    'max_ram_mb' => $group->max_ram_mb,
                    'max_storage_gb' => $group->max_storage_gb,
                    'max_ips' => $group->max_ips,
                    'max_snapshots' => $group->max_snapshots ?? 3,
                ]
            );

            $this->logger()->record(new AuditEntry(
                action: AuditAction::UserQuotaAssigned,
                outcome: AuditOutcome::Success,
                subject: $user,
                tenantUserId: $user->id,
                visibility: 'tenant',
                context: [
                    'quota_group_id' => $group->id,
                    'quota_group_name' => $group->name,
                ],
            ));
        });

        return $group;
    }
}
