<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\DTO\Network\CreateIpPoolDTO;
use App\Exceptions\IpPoolExhaustedException;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\HypervisorGateway;
use App\Services\Network\CidrHelper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service managing ranged IPAM allocations, routed pool capacity, and cooldown releases.
 */
final class IpManagementService
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
     * Atomically allocate the lowest available IP address in the given pool for a virtual machine.
     *
     *
     * @throws IpPoolExhaustedException
     */
    public function allocateIp(IpPool $pool, VirtualMachine $vm, User $user): IpAllocation
    {
        return DB::transaction(function () use ($pool, $vm, $user): IpAllocation {
            // 1. Acquire pessimistic lock on the pool row to serialize concurrent allocations
            /** @var IpPool $lockedPool */
            $lockedPool = IpPool::where('id', $pool->id)->lockForUpdate()->firstOrFail();

            if (! $lockedPool->active) {
                throw new IpPoolExhaustedException('The selected IP pool is disabled.');
            }

            // 2. Query occupied IP addresses (active allocations + cooling allocations still in quarantine)
            $cooldownLimit = now()->subSeconds($lockedPool->cooldown_seconds);

            /** @var array<string, bool> $occupied */
            $occupied = [];
            $occupiedAllocations = IpAllocation::where('pool_id', $lockedPool->id)
                ->where(function (Builder $query) use ($cooldownLimit): void {
                    $query->where('status', 'allocated')
                        ->orWhere(function (Builder $sub) use ($cooldownLimit): void {
                            $sub->where('status', 'cooling')
                                ->where('released_at', '>', $cooldownLimit);
                        });
                })
                ->get(['ip_string']);

            foreach ($occupiedAllocations as $alloc) {
                $occupied[$alloc->ip_string] = true;
            }

            // 3. Prepare excluded set (including gateway and explicitly excluded IPs)
            /** @var array<string, bool> $excluded */
            $excluded = [];
            if ($lockedPool->gateway !== null && $lockedPool->gateway !== '') {
                $excluded[$lockedPool->gateway] = true;
            }
            if (is_array($lockedPool->excluded_ips)) {
                foreach ($lockedPool->excluded_ips as $exIp) {
                    if (is_string($exIp)) {
                        $excluded[$exIp] = true;
                    }
                }
            }

            // 4. Scan the usable address range deterministically
            $firstLong = ip2long($lockedPool->first_usable);
            $lastLong = ip2long($lockedPool->last_usable);

            if ($firstLong === false || $lastLong === false || $lastLong < $firstLong) {
                throw new IpPoolExhaustedException('Invalid IP pool boundary configuration.');
            }

            $candidateIp = null;
            for ($i = $firstLong; $i <= $lastLong; $i++) {
                $candidate = long2ip($i);
                if (! isset($excluded[$candidate]) && ! isset($occupied[$candidate])) {
                    $candidateIp = $candidate;
                    break;
                }
            }

            if ($candidateIp === null) {
                throw new IpPoolExhaustedException(sprintf('No available IP addresses in pool "%s" for host #%d.', $lockedPool->name, $lockedPool->host_id));
            }

            // 5. Generate Locally Administered Unicast MAC (02:54:xx:xx:xx:xx)
            $mac = $this->generateMacAddress();

            // 6. Update existing cooling allocation or create new allocation atomically
            $rawAddress = inet_pton($candidateIp);
            if ($rawAddress === false) {
                throw new IpPoolExhaustedException('Failed to convert IP to binary representation.');
            }

            /** @var IpAllocation $allocation */
            $allocation = IpAllocation::updateOrCreate(
                [
                    'pool_id' => $lockedPool->id,
                    'address' => $rawAddress,
                ],
                [
                    'ip_string' => $candidateIp,
                    'virtual_machine_id' => $vm->id,
                    'user_id' => $user->id,
                    'mac_address' => $mac,
                    'status' => 'allocated',
                    'allocated_at' => now(),
                    'released_at' => null,
                ]
            );

            // 7. Synchronize assigned address and status onto VM
            $vm->ipv4_address = $candidateIp;
            $vm->network_status = 'assigned';
            $vm->save();

            $this->logger()->record(new AuditEntry(
                action: AuditAction::IpAllocated,
                outcome: AuditOutcome::Success,
                subject: $allocation,
                tenantUserId: $user->id,
                visibility: 'tenant',
                context: [
                    'ip' => $candidateIp,
                    'virtual_machine_id' => $vm->id,
                    'pool_id' => $lockedPool->id,
                ],
            ));

            return $allocation;
        });
    }

    /**
     * Release an IP allocation assigned to a virtual machine and transition it to cooling state.
     */
    public function releaseIp(VirtualMachine $vm): void
    {
        DB::transaction(function () use ($vm): void {
            $allocations = IpAllocation::where('virtual_machine_id', $vm->id)
                ->where('status', 'allocated')
                ->get();

            foreach ($allocations as $alloc) {
                $this->logger()->record(new AuditEntry(
                    action: AuditAction::IpReleased,
                    outcome: AuditOutcome::Success,
                    subject: $alloc,
                    tenantUserId: $alloc->user_id,
                    visibility: 'tenant',
                    context: [
                        'ip' => $alloc->ip_string,
                        'pool_id' => $alloc->pool_id,
                    ],
                ));
            }

            IpAllocation::where('virtual_machine_id', $vm->id)
                ->where('status', 'allocated')
                ->update([
                    'virtual_machine_id' => null,
                    'status' => 'cooling',
                    'released_at' => now(),
                ]);

            $vm->ipv4_address = null;
            $vm->network_status = 'unassigned';
            $vm->save();
        });
    }

    /**
     * Forcefully release an allocation immediately (admin action).
     */
    public function forceReleaseAllocation(IpAllocation $allocation): void
    {
        DB::transaction(function () use ($allocation): void {
            if ($allocation->virtual_machine_id !== null) {
                VirtualMachine::where('id', $allocation->virtual_machine_id)->update([
                    'ipv4_address' => null,
                    'network_status' => null,
                ]);
            }

            $this->logger()->record(new AuditEntry(
                action: AuditAction::IpReleased,
                outcome: AuditOutcome::Success,
                subject: $allocation,
                tenantUserId: $allocation->user_id,
                visibility: 'tenant',
                context: [
                    'ip' => $allocation->ip_string,
                    'pool_id' => $allocation->pool_id,
                ],
            ));

            $allocation->update([
                'virtual_machine_id' => null,
                'status' => 'cooling',
                'released_at' => now(),
            ]);
        });
    }

    /**
     * Generate a Locally Administered Unicast MAC address (OUI prefix 02:54).
     */
    public function generateMacAddress(): string
    {
        return sprintf(
            '02:54:%02x:%02x:%02x:%02x',
            random_int(0, 255),
            random_int(0, 255),
            random_int(0, 255),
            random_int(0, 255)
        );
    }

    /**
     * Compute capacity and live utilization metrics for a pool given its counts.
     *
     * @return array{total: int, allocated: int, cooling: int, free: int, percent: float}
     */
    private function computeUtilizationMetrics(IpPool $pool, ?int $allocatedCount = null, ?int $coolingCount = null): array
    {
        $firstLong = ip2long($pool->first_usable);
        $lastLong = ip2long($pool->last_usable);

        if ($firstLong === false || $lastLong === false || $lastLong < $firstLong) {
            return [
                'total' => 0,
                'allocated' => 0,
                'cooling' => 0,
                'free' => 0,
                'percent' => 0.0,
            ];
        }

        $rawTotal = ($lastLong - $firstLong) + 1;

        // Excluded IP count
        $excludedCount = 0;
        $excludedMap = [];
        if ($pool->gateway !== null && $pool->gateway !== '') {
            $excludedMap[$pool->gateway] = true;
        }
        if (is_array($pool->excluded_ips)) {
            foreach ($pool->excluded_ips as $ex) {
                if (is_string($ex)) {
                    $excludedMap[$ex] = true;
                }
            }
        }
        foreach (array_keys($excludedMap) as $exIp) {
            $exLong = ip2long($exIp);
            if ($exLong !== false && $exLong >= $firstLong && $exLong <= $lastLong) {
                $excludedCount++;
            }
        }

        $usableTotal = max(0, $rawTotal - $excludedCount);

        if ($allocatedCount === null || $coolingCount === null) {
            $allocatedCount = $pool->allocations()->where('status', 'allocated')->count();
            $cooldownLimit = now()->subSeconds($pool->cooldown_seconds);
            $coolingCount = $pool->allocations()
                ->where('status', 'cooling')
                ->where('released_at', '>', $cooldownLimit)
                ->count();
        }

        $occupiedCount = $allocatedCount + $coolingCount;
        $freeCount = max(0, $usableTotal - $occupiedCount);
        $percent = $usableTotal > 0 ? round(($occupiedCount / $usableTotal) * 100, 1) : 0.0;

        return [
            'total' => $usableTotal,
            'allocated' => $allocatedCount,
            'cooling' => $coolingCount,
            'free' => $freeCount,
            'percent' => $percent,
        ];
    }

    /**
     * Calculate live capacity and utilization metrics for an IP pool.
     *
     * @return array{total: int, allocated: int, cooling: int, free: int, percent: float}
     */
    public function getPoolUtilization(IpPool $pool): array
    {
        return $this->computeUtilizationMetrics($pool);
    }

    /**
     * Build the filtered query for IP allocations.
     *
     * @param  array{user_id?: int|null, host_id?: int|null, pool_id?: int|null, status?: string|null, search?: string|null}  $filters
     * @return Builder<IpAllocation>
     */
    private function buildAllocationsQuery(User $user, array $filters = []): Builder
    {
        /** @var Builder<IpAllocation> $query */
        $query = IpAllocation::with(['pool.host', 'virtualMachine.host', 'user:id,name,email']);

        if (! $user->hasRole('admin')) {
            $query->where('user_id', $user->id);
        } else {
            if (isset($filters['user_id'])) {
                $query->where('user_id', $filters['user_id']);
            }
        }

        if (isset($filters['host_id'])) {
            $hostId = $filters['host_id'];
            $query->whereIn('pool_id', IpPool::query()->where('host_id', $hostId)->select('id'));
        }

        if (isset($filters['pool_id'])) {
            $query->where('pool_id', $filters['pool_id']);
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search): void {
                $q->where('ip_string', 'like', "%{$search}%")
                    ->orWhere('mac_address', 'like', "%{$search}%")
                    ->orWhereHas('virtualMachine', fn (Builder $sub): Builder => $sub->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('pool', fn (Builder $sub): Builder => $sub->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('user', function (Builder $uq) use ($search): void {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        return $query->orderBy('pool_id')->orderBy('address');
    }

    /**
     * Prune IP allocations whose cooling quarantine period has expired.
     */
    public function pruneExpiredCoolingAllocations(?int $poolId = null): int
    {
        $poolsQuery = DB::table('ip_pools')->select(['id', 'cooldown_seconds']);

        if ($poolId !== null) {
            $poolsQuery->where('id', $poolId);
        }

        $pools = $poolsQuery->get();
        $now = now();
        $deletedTotal = 0;

        foreach ($pools as $pool) {
            $cooldownSeconds = is_numeric($pool->cooldown_seconds) ? max(0, (int) $pool->cooldown_seconds) : 0;
            $cutoff = $now->copy()->subSeconds($cooldownSeconds);

            $deleted = DB::table('ip_allocations')
                ->where('pool_id', is_numeric($pool->id) ? (int) $pool->id : 0)
                ->where('status', 'cooling')
                ->whereNotNull('released_at')
                ->where('released_at', '<=', $cutoff)
                ->delete();

            $deletedTotal += $deleted;
        }

        return $deletedTotal;
    }

    /**
     * List all IP allocations accessible by the specified user.
     *
     * @param  array{user_id?: int|null, host_id?: int|null, pool_id?: int|null, status?: string|null, search?: string|null}  $filters
     * @return Collection<int, IpAllocation>
     */
    public function listForUser(User $user, array $filters = []): Collection
    {
        return $this->buildAllocationsQuery($user, $filters)->get();
    }

    /**
     * Paginate IP allocations accessible by the specified user.
     *
     * @param  array{user_id?: int|null, host_id?: int|null, pool_id?: int|null, status?: string|null, search?: string|null}  $filters
     * @return LengthAwarePaginator<int, IpAllocation>
     */
    public function paginateForUser(User $user, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->buildAllocationsQuery($user, $filters)->paginate($perPage);
    }

    /**
     * Pre-compute live utilization metrics for a collection of pools in batch queries (avoiding N+1).
     *
     * @param  iterable<IpPool>  $pools
     */
    public function attachUtilizationToPools(iterable $pools): void
    {
        $poolList = is_array($pools) ? $pools : iterator_to_array($pools);
        if (empty($poolList)) {
            return;
        }

        $poolIds = array_values(array_unique(array_filter(array_map(static fn (IpPool $p): int => $p->id, $poolList))));
        if ($poolIds === []) {
            return;
        }

        /** @var array<int, int> $poolCooldowns */
        $poolCooldowns = [];
        foreach ($poolList as $pool) {
            $poolCooldowns[$pool->id] = $pool->cooldown_seconds;
        }

        /** @var array<int, int> $allocatedCounts */
        $allocatedCounts = [];
        $allocatedRows = DB::table('ip_allocations')
            ->select('pool_id')
            ->selectRaw('COUNT(*) as aggregate_count')
            ->whereIn('pool_id', $poolIds)
            ->where('status', 'allocated')
            ->groupBy('pool_id')
            ->get();

        foreach ($allocatedRows as $row) {
            $pid = is_numeric($row->pool_id) ? (int) $row->pool_id : 0;
            $allocatedCounts[$pid] = is_numeric($row->aggregate_count) ? (int) $row->aggregate_count : 0;
        }

        /** @var array<int, int> $coolingCounts */
        $coolingCounts = [];
        $coolingRows = DB::table('ip_allocations')
            ->whereIn('pool_id', $poolIds)
            ->where('status', 'cooling')
            ->whereNotNull('released_at')
            ->get(['pool_id', 'released_at']);

        $now = now();
        foreach ($coolingRows as $row) {
            $pid = is_numeric($row->pool_id) ? (int) $row->pool_id : 0;
            if (! is_string($row->released_at) || $row->released_at === '' || ! array_key_exists($pid, $poolCooldowns)) {
                continue;
            }
            $cooldownSeconds = $poolCooldowns[$pid];
            $releasedAt = Carbon::parse($row->released_at);
            if ($releasedAt->copy()->addSeconds($cooldownSeconds)->greaterThan($now)) {
                $coolingCounts[$pid] = ($coolingCounts[$pid] ?? 0) + 1;
            }
        }

        foreach ($poolList as $pool) {
            $allocated = $allocatedCounts[$pool->id] ?? 0;
            $cooling = $coolingCounts[$pool->id] ?? 0;
            $pool->utilization = $this->computeUtilizationMetrics($pool, $allocated, $cooling);
        }
    }

    /**
     * List pools with live utilization metrics.
     *
     * @param  array{host_id?: int|null}  $filters
     * @return Collection<int, IpPool>
     */
    public function listPoolsWithStats(array $filters = []): Collection
    {
        $query = IpPool::with(['host']);

        if (isset($filters['host_id'])) {
            $query->where('host_id', $filters['host_id']);
        }

        $pools = $query->get();
        $this->attachUtilizationToPools($pools);

        return $pools;
    }

    /**
     * Paginate pools with live utilization metrics.
     *
     * @param  array{host_id?: int|null}  $filters
     * @return LengthAwarePaginator<int, IpPool>
     */
    public function paginatePoolsWithStats(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $query = IpPool::with(['host']);

        if (isset($filters['host_id'])) {
            $query->where('host_id', $filters['host_id']);
        }

        $paginator = $query->paginate($perPage);
        $this->attachUtilizationToPools($paginator->items());

        return $paginator;
    }

    /**
     * Emergency admin release of an IP allocation.
     */
    public function emergencyReleaseAllocation(IpAllocation $allocation, bool $forceDelete = false): void
    {
        DB::transaction(function () use ($allocation, $forceDelete): void {
            if ($allocation->virtual_machine_id !== null) {
                VirtualMachine::where('id', $allocation->virtual_machine_id)->update([
                    'ipv4_address' => null,
                    'network_status' => null,
                ]);
            }

            $this->logger()->record(new AuditEntry(
                action: AuditAction::IpReleased,
                outcome: AuditOutcome::Success,
                subject: $allocation,
                tenantUserId: $allocation->user_id,
                visibility: 'tenant',
                context: [
                    'ip' => $allocation->ip_string,
                    'pool_id' => $allocation->pool_id,
                ],
            ));

            if ($forceDelete || $allocation->status === 'cooling') {
                $allocation->delete();
            } else {
                $allocation->update([
                    'virtual_machine_id' => null,
                    'status' => 'cooling',
                    'released_at' => now(),
                ]);
            }
        });
    }

    /**
     * Reassign an IP allocation to a different virtual machine or detach it.
     */
    public function reassignIp(IpAllocation $allocation, ?int $newVmId): IpAllocation
    {
        return DB::transaction(function () use ($allocation, $newVmId): IpAllocation {
            $oldVmId = $allocation->virtual_machine_id;
            $allocation->virtual_machine_id = $newVmId;
            $allocation->save();

            $this->logger()->record(new AuditEntry(
                action: AuditAction::IpReassigned,
                outcome: AuditOutcome::Success,
                subject: $allocation,
                tenantUserId: $allocation->user_id,
                visibility: 'tenant',
                context: [
                    'ip' => $allocation->ip_string,
                    'old_virtual_machine_id' => $oldVmId,
                    'new_virtual_machine_id' => $newVmId,
                ],
            ));

            return $allocation;
        });
    }

    /**
     * Check if a candidate CIDR overlaps with existing pools according to isolation rules.
     * Rule: Must not overlap any pool on the same host; for public pools, must not overlap any public pool on any host.
     */
    public function checkPoolOverlap(string $cidr, int $hostId, bool $isPublic, ?int $ignorePoolId = null): ?IpPool
    {
        $candidateRange = CidrHelper::subnetRange($cidr);
        if ($candidateRange === null) {
            return null;
        }

        $existingPools = IpPool::query()
            ->when($ignorePoolId !== null, fn (Builder $q) => $q->where('id', '!=', $ignorePoolId))
            ->where(function (Builder $q) use ($hostId, $isPublic): void {
                $q->where('host_id', $hostId);
                if ($isPublic) {
                    $q->orWhere('is_public', true);
                }
            })
            ->get();

        foreach ($existingPools as $existingPool) {
            if ($isPublic && ! $existingPool->is_public && $existingPool->host_id !== $hostId) {
                continue;
            }

            if (CidrHelper::overlaps($cidr, $existingPool->cidr)) {
                return $existingPool;
            }
        }

        return null;
    }

    /**
     * Create a new routed or manual IP subnet pool.
     */
    public function createPool(CreateIpPoolDTO $dto): IpPool
    {
        return DB::transaction(function () use ($dto): IpPool {
            $cidr = $dto->cidr;

            if ($conflictingPool = $this->checkPoolOverlap($cidr, $dto->hostId, $dto->isPublic)) {
                throw ValidationException::withMessages([
                    'cidr' => [sprintf(
                        "Subnet '%s' overlaps with existing pool '%s' (ID #%d, CIDR %s).",
                        $cidr,
                        $conflictingPool->name,
                        $conflictingPool->id,
                        $conflictingPool->cidr
                    )],
                ]);
            }

            $calculated = CidrHelper::calculateUsableRange($cidr);
            if ($calculated === null) {
                throw ValidationException::withMessages([
                    'cidr' => ['Invalid CIDR network format.'],
                ]);
            }

            $firstUsable = $dto->firstUsable ?: $calculated['first'];
            $lastUsable = $dto->lastUsable ?: $calculated['last'];

            $excluded = $dto->excludedIps ?? [];
            if ($dto->gateway !== null && $dto->gateway !== '' && ! in_array($dto->gateway, $excluded, true)) {
                $excluded[] = $dto->gateway;
            }

            /** @var IpPool $pool */
            $pool = IpPool::create([
                'name' => $dto->name,
                'host_id' => $dto->hostId,
                'cidr' => $cidr,
                'first_usable' => $firstUsable,
                'last_usable' => $lastUsable,
                'network_mode' => $dto->networkMode,
                'parent_interface' => $dto->parentInterface,
                'gateway' => $dto->gateway,
                'dns_servers' => $dto->dnsServers ?? ['1.1.1.1', '8.8.8.8'],
                'excluded_ips' => array_values(array_unique(array_filter($excluded, 'is_string'))),
                'cooldown_seconds' => $dto->cooldownSeconds,
                'is_public' => $dto->isPublic,
                'active' => $dto->active,
                'description' => $dto->description,
                'subnet' => $cidr,
            ]);

            $this->logger()->record(new AuditEntry(
                action: AuditAction::IpPoolCreated,
                outcome: AuditOutcome::Success,
                subject: $pool,
                tenantUserId: null,
                visibility: 'admin',
                context: [
                    'name' => $pool->name,
                    'cidr' => $pool->cidr,
                    'host_id' => $pool->host_id,
                ],
            ));

            return $pool->fresh(['host']) ?? $pool;
        });
    }

    /**
     * Update an existing IP pool.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function updatePool(IpPool $pool, array $data): IpPool
    {
        return DB::transaction(function () use ($pool, $data): IpPool {
            $hasAllocations = $pool->allocations()->count() > 0;

            if ($hasAllocations) {
                if (isset($data['cidr']) && $data['cidr'] !== $pool->cidr) {
                    throw ValidationException::withMessages(['cidr' => ['CIDR cannot be modified when allocations exist in the pool.']]);
                }
                if (isset($data['host_id']) && (int) $data['host_id'] !== $pool->host_id) {
                    throw ValidationException::withMessages(['host_id' => ['Host node cannot be modified when allocations exist in the pool.']]);
                }
                if (isset($data['network_mode']) && $data['network_mode'] !== $pool->network_mode) {
                    throw ValidationException::withMessages(['network_mode' => ['Network mode cannot be modified when allocations exist in the pool.']]);
                }
            }

            $effectiveCidr = (string) ($data['cidr'] ?? $pool->cidr);
            $effectiveHostId = isset($data['host_id']) ? (int) $data['host_id'] : $pool->host_id;
            $effectiveIsPublic = isset($data['is_public']) ? (bool) $data['is_public'] : $pool->is_public;

            if ($conflictingPool = $this->checkPoolOverlap($effectiveCidr, $effectiveHostId, $effectiveIsPublic, $pool->id)) {
                throw ValidationException::withMessages([
                    'cidr' => [sprintf(
                        "Subnet '%s' overlaps with existing pool '%s' (ID #%d, CIDR %s).",
                        $effectiveCidr,
                        $conflictingPool->name,
                        $conflictingPool->id,
                        $conflictingPool->cidr
                    )],
                ]);
            }

            $firstUsable = (string) ($data['first_usable'] ?? $pool->first_usable);
            $lastUsable = (string) ($data['last_usable'] ?? $pool->last_usable);
            $firstLong = ip2long($firstUsable);
            $lastLong = ip2long($lastUsable);

            if ($firstLong !== false && $lastLong !== false) {
                $allocations = $pool->allocations()
                    ->whereIn('status', ['allocated', 'cooling'])
                    ->get(['ip_string']);

                foreach ($allocations as $alloc) {
                    $allocLong = ip2long($alloc->ip_string);
                    if ($allocLong !== false && ($allocLong < $firstLong || $allocLong > $lastLong)) {
                        throw ValidationException::withMessages([
                            'first_usable' => [sprintf(
                                'Cannot adjust IP range: existing allocation %s falls outside the new range (%s - %s).',
                                $alloc->ip_string,
                                $firstUsable,
                                $lastUsable
                            )],
                        ]);
                    }
                }
            }

            $excluded = isset($data['excluded_ips']) && is_array($data['excluded_ips'])
                ? $data['excluded_ips']
                : ($pool->excluded_ips ?? []);

            $gateway = array_key_exists('gateway', $data) ? $data['gateway'] : $pool->gateway;
            if ($gateway !== null && $gateway !== '' && ! in_array($gateway, $excluded, true)) {
                $excluded[] = $gateway;
            }

            $fieldsToUpdate = [
                'name' => $data['name'] ?? $pool->name,
                'description' => array_key_exists('description', $data) ? $data['description'] : $pool->description,
                'dns_servers' => $data['dns_servers'] ?? $pool->dns_servers,
                'excluded_ips' => array_values(array_unique(array_filter($excluded, 'is_string'))),
                'cooldown_seconds' => isset($data['cooldown_seconds']) ? (int) $data['cooldown_seconds'] : $pool->cooldown_seconds,
                'active' => isset($data['active']) ? (bool) $data['active'] : $pool->active,
                'is_public' => $effectiveIsPublic,
                'gateway' => $gateway,
                'first_usable' => $firstUsable,
                'last_usable' => $lastUsable,
            ];

            if (! $hasAllocations) {
                if (isset($data['cidr'])) {
                    $fieldsToUpdate['cidr'] = $data['cidr'];
                    $fieldsToUpdate['subnet'] = $data['cidr'];
                }
                if (isset($data['host_id'])) {
                    $fieldsToUpdate['host_id'] = (int) $data['host_id'];
                }
                if (isset($data['network_mode'])) {
                    $fieldsToUpdate['network_mode'] = $data['network_mode'];
                }
                if (array_key_exists('parent_interface', $data)) {
                    $fieldsToUpdate['parent_interface'] = is_string($data['parent_interface']) ? $data['parent_interface'] : '';
                }
            }

            $pool->update($fieldsToUpdate);

            return $pool->fresh(['host']) ?? $pool;
        });
    }

    /**
     * Run preflight checks on an IP pool.
     *
     * @return array{ok: bool, checks: list<array{name: string, ok: bool, hint: string}>}
     */
    public function preflightPool(IpPool $pool): array
    {
        $pool->loadMissing('host');
        $checks = [];
        $overallOk = true;

        // 1. Host reachable via HypervisorGateway
        $hostReachable = false;
        $networkNames = [];
        try {
            $gateway = app(HypervisorGateway::class);
            $client = $gateway->client($pool->host);
            $res = $client->get('/1.0/networks');

            if ($res->successful()) {
                $hostReachable = true;
                $metadata = $res->json('metadata') ?? [];
                if (is_array($metadata)) {
                    foreach ($metadata as $item) {
                        if (is_string($item)) {
                            $networkNames[] = basename($item);
                        } elseif (is_array($item) && isset($item['name']) && is_string($item['name'])) {
                            $networkNames[] = $item['name'];
                        }
                    }
                }
                $checks[] = [
                    'name' => 'Host reachable',
                    'ok' => true,
                    'hint' => sprintf("Hypervisor host '%s' responded successfully via Incus API.", $pool->host->name),
                ];
            } else {
                $overallOk = false;
                $checks[] = [
                    'name' => 'Host reachable',
                    'ok' => false,
                    'hint' => sprintf('Host returned HTTP status %d: %s', $res->status(), $res->body()),
                ];
            }
        } catch (\Throwable $e) {
            $overallOk = false;
            $checks[] = [
                'name' => 'Host reachable',
                'ok' => false,
                'hint' => sprintf("Failed to connect to host '%s': %s", $pool->host->name, $e->getMessage()),
            ];
        }

        // 2. Parent interface existence on host
        if (! $hostReachable) {
            $overallOk = false;
            $checks[] = [
                'name' => 'Parent interface exists',
                'ok' => false,
                'hint' => sprintf("Cannot verify parent interface '%s' because host is unreachable.", $pool->parent_interface),
            ];
        } else {
            $parentInterface = $pool->parent_interface ?: 'eth0';
            if (in_array($parentInterface, $networkNames, true)) {
                $checks[] = [
                    'name' => 'Parent interface exists',
                    'ok' => true,
                    'hint' => sprintf("Parent interface '%s' confirmed on host '%s'.", $parentInterface, $pool->host->name),
                ];
            } else {
                $overallOk = false;
                $checks[] = [
                    'name' => 'Parent interface exists',
                    'ok' => false,
                    'hint' => sprintf("Parent interface '%s' not found on host '%s'. Available networks: %s.", $parentInterface, $pool->host->name, implode(', ', $networkNames) ?: 'none'),
                ];
            }
        }

        // 3. Pool range consistent with CIDR and gateway
        $rangeConsistent = true;
        $rangeHints = [];
        $range = CidrHelper::subnetRange($pool->cidr);
        if ($range === null) {
            $rangeConsistent = false;
            $rangeHints[] = 'Invalid CIDR format.';
        } else {
            [$network, $broadcast] = $range;
            $firstLong = CidrHelper::ipToUnsigned($pool->first_usable);
            $lastLong = CidrHelper::ipToUnsigned($pool->last_usable);

            if ($firstLong === null || $firstLong < $network || $firstLong > $broadcast) {
                $rangeConsistent = false;
                $rangeHints[] = sprintf("First usable IP '%s' is outside CIDR %s.", $pool->first_usable, $pool->cidr);
            }
            if ($lastLong === null || $lastLong < $network || $lastLong > $broadcast) {
                $rangeConsistent = false;
                $rangeHints[] = sprintf("Last usable IP '%s' is outside CIDR %s.", $pool->last_usable, $pool->cidr);
            }
            if ($firstLong !== null && $lastLong !== null && $firstLong > $lastLong) {
                $rangeConsistent = false;
                $rangeHints[] = sprintf("First usable IP '%s' is greater than last usable '%s'.", $pool->first_usable, $pool->last_usable);
            }
            if (! empty($pool->gateway)) {
                $gwLong = CidrHelper::ipToUnsigned($pool->gateway);
                if ($gwLong === null || $gwLong < $network || $gwLong > $broadcast) {
                    $rangeConsistent = false;
                    $rangeHints[] = sprintf("Gateway IP '%s' is outside CIDR %s.", $pool->gateway, $pool->cidr);
                }
            }
        }

        if ($rangeConsistent) {
            $checks[] = [
                'name' => 'Pool range consistent',
                'ok' => true,
                'hint' => 'Usable IP range and gateway are consistent with CIDR block.',
            ];
        } else {
            $overallOk = false;
            $checks[] = [
                'name' => 'Pool range consistent',
                'ok' => false,
                'hint' => implode(' ', $rangeHints),
            ];
        }

        // 4. Host kernel routing & proxy ARP hint
        $checks[] = [
            'name' => 'Host sysctl & proxy ARP',
            'ok' => true,
            'hint' => 'Host sysctl (net.ipv4.ip_forward=1, proxy_arp) cannot be verified remotely via Incus API. The operator must ensure IP forwarding and proxy ARP are configured on the hypervisor host.',
        ];

        $auditChecks = array_map(static fn (array $c): array => [
            'name' => $c['name'],
            'ok' => (bool) $c['ok'],
        ], $checks);

        $this->logger()->record(new AuditEntry(
            action: AuditAction::IpPoolPreflightRun,
            outcome: AuditOutcome::Success,
            subject: $pool,
            tenantUserId: null,
            visibility: 'admin',
            context: [
                'ok' => $overallOk,
                'checks' => $auditChecks,
            ],
        ));

        return [
            'ok' => $overallOk,
            'checks' => $checks,
        ];
    }

    /**
     * Delete an IP pool ensuring no active assignments exist.
     */
    public function deletePool(IpPool $ipPool): void
    {
        $activeCount = $ipPool->allocations()->where('status', 'allocated')->count();

        if ($activeCount > 0) {
            abort(422, 'Cannot delete pool with active IP allocations.');
        }

        DB::transaction(function () use ($ipPool): void {
            $this->logger()->record(new AuditEntry(
                action: AuditAction::IpPoolDeleted,
                outcome: AuditOutcome::Success,
                subject: $ipPool,
                tenantUserId: null,
                visibility: 'admin',
                context: [
                    'name' => $ipPool->name,
                    'cidr' => $ipPool->cidr,
                    'host_id' => $ipPool->host_id,
                ],
            ));

            $ipPool->allocations()->delete();
            $ipPool->delete();
        });
    }
}
