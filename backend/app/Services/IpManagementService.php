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
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
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

        $rawSearch = $filters['search'] ?? null;
        $search = is_string($rawSearch) ? trim($rawSearch) : '';

        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);

            if (preg_match('/^\d{1,3}(\.\d{1,3}){0,3}\.?$/', $search) === 1) {
                $this->applyEscapedLike($query, 'ip_string', "{$escaped}%");
            } elseif (str_contains($search, ':') && preg_match('/^[0-9a-fA-F:]+$/', $search) === 1) {
                $this->applyEscapedLike($query, 'mac_address', strtolower($escaped).'%');
            } else {
                $contains = "%{$escaped}%";
                $query->where(function (Builder $q) use ($contains): void {
                    $q->whereHas('virtualMachine', function (Builder $sub) use ($contains): void {
                        $this->applyEscapedLike($sub, 'name', $contains);
                    })
                        ->orWhereHas('pool', function (Builder $sub) use ($contains): void {
                            $this->applyEscapedLike($sub, 'name', $contains);
                        })
                        ->orWhereHas('user', function (Builder $uq) use ($contains): void {
                            $this->applyEscapedLike($uq, 'name', $contains);
                            $this->applyEscapedLike($uq, 'email', $contains, true);
                        });
                });
            }
        }

        return $query->orderBy('pool_id')->orderBy('address');
    }

    /**
     * Apply a LIKE filter with wildcard escaping compatible with both MySQL and SQLite.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  literal-string  $column
     */
    private function applyEscapedLike(Builder $query, string $column, string $pattern, bool $booleanOr = false): void
    {
        $connection = $query->getConnection();
        $isSqlite = $connection instanceof Connection && $connection->getDriverName() === 'sqlite';

        if ($isSqlite) {
            $sql = "{$column} LIKE ? ESCAPE '\\'";
            if ($booleanOr) {
                $query->orWhereRaw($sql, [$pattern]);
            } else {
                $query->whereRaw($sql, [$pattern]);
            }

            return;
        }

        if ($booleanOr) {
            $query->getQuery()->orWhere($column, 'like', $pattern);
        } else {
            $query->getQuery()->where($column, 'like', $pattern);
        }
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
     * Paginate addresses within an IP pool including free, allocated, cooling, and excluded entries.
     *
     * @return array{
     *     data: list<array{
     *         ip: string,
     *         state: 'free'|'allocated'|'cooling'|'excluded',
     *         is_gateway: bool,
     *         cooldown_remaining_seconds: int,
     *         allocation: array{
     *             id: int,
     *             mac_address: string|null,
     *             status: string,
     *             allocated_at: string|null,
     *             released_at: string|null,
     *             virtual_machine: array{id: int, name: string, status: string}|null,
     *             user: array{id: int, name: string, email: string}|null,
     *         }|null,
     *     }>,
     *     meta: array{
     *         current_page: int,
     *         last_page: int,
     *         per_page: int,
     *         total: int,
     *         from: int|null,
     *         to: int|null,
     *     },
     * }
     */
    public function paginatePoolAddresses(
        IpPool $pool,
        string $state = 'all',
        ?string $search = null,
        int $page = 1,
        int $perPage = 50,
    ): array {
        $pool->loadMissing('host');
        $this->attachUtilizationToPools([$pool]);

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $normalizedSearch = $search !== null ? trim($search) : '';

        $firstLong = CidrHelper::ipToUnsigned($pool->first_usable);
        $lastLong = CidrHelper::ipToUnsigned($pool->last_usable);

        if ($firstLong === null || $lastLong === null || $lastLong < $firstLong) {
            return [
                'data' => [],
                'meta' => $this->buildPoolAddressPaginationMeta(0, $page, $perPage, 0),
            ];
        }

        $nonFreeMap = $this->buildNonFreePoolAddressMap($pool, $firstLong, $lastLong);
        $offset = ($page - 1) * $perPage;

        if ($normalizedSearch === '') {
            if ($state === 'all') {
                $total = ($lastLong - $firstLong) + 1;
                $items = $this->sliceAllPoolAddresses($firstLong, $lastLong, $nonFreeMap, $offset, $perPage);
            } elseif ($state === 'free') {
                $nonFreeLongs = array_keys($nonFreeMap);
                $total = max(0, (($lastLong - $firstLong) + 1) - count($nonFreeLongs));
                $items = $this->sliceFreePoolAddresses($firstLong, $lastLong, $nonFreeLongs, $total, $offset, $perPage);
            } else {
                $filtered = array_values(array_filter(
                    $nonFreeMap,
                    static fn (array $entry): bool => $entry['state'] === $state,
                ));
                $total = count($filtered);
                $items = array_slice($filtered, $offset, $perPage);
            }

            return [
                'data' => $items,
                'meta' => $this->buildPoolAddressPaginationMeta($total, $page, $perPage, count($items)),
            ];
        }

        if ($state === 'allocated' || $state === 'cooling' || $state === 'excluded') {
            $filtered = array_values(array_filter(
                $nonFreeMap,
                static fn (array $entry): bool => $entry['state'] === $state && str_starts_with($entry['ip'], $normalizedSearch),
            ));
            $total = count($filtered);
            $items = array_slice($filtered, $offset, $perPage);

            return [
                'data' => $items,
                'meta' => $this->buildPoolAddressPaginationMeta($total, $page, $perPage, count($items)),
            ];
        }

        [$scanFirst, $scanLast] = $this->narrowPrefixSearchRange($normalizedSearch, $firstLong, $lastLong);
        $total = 0;
        $items = [];

        if ($scanFirst <= $scanLast) {
            for ($ipLong = $scanFirst; $ipLong <= $scanLast; $ipLong++) {
                if ($state === 'free' && isset($nonFreeMap[$ipLong])) {
                    continue;
                }

                $ip = long2ip($ipLong);
                if (! str_starts_with($ip, $normalizedSearch)) {
                    continue;
                }

                if ($total >= $offset && count($items) < $perPage) {
                    $items[] = $nonFreeMap[$ipLong] ?? $this->makePoolAddressEntry($ip, 'free', false, 0, null);
                }
                $total++;
            }
        }

        return [
            'data' => $items,
            'meta' => $this->buildPoolAddressPaginationMeta($total, $page, $perPage, count($items)),
        ];
    }

    /**
     * Build a sorted map of all non-free (allocated, actively cooling, or excluded) addresses in the pool range.
     *
     * @return array<int, array{
     *     ip: string,
     *     state: 'free'|'allocated'|'cooling'|'excluded',
     *     is_gateway: bool,
     *     cooldown_remaining_seconds: int,
     *     allocation: array{
     *         id: int,
     *         mac_address: string|null,
     *         status: string,
     *         allocated_at: string|null,
     *         released_at: string|null,
     *         virtual_machine: array{id: int, name: string, status: string}|null,
     *         user: array{id: int, name: string, email: string}|null,
     *     }|null,
     * }>
     */
    private function buildNonFreePoolAddressMap(IpPool $pool, int $firstLong, int $lastLong): array
    {
        $now = now();
        $gatewayLong = ($pool->gateway !== null && $pool->gateway !== '')
            ? CidrHelper::ipToUnsigned($pool->gateway)
            : null;

        /** @var array<int, true> $excludedSet */
        $excludedSet = [];
        if ($gatewayLong !== null && $gatewayLong >= $firstLong && $gatewayLong <= $lastLong) {
            $excludedSet[$gatewayLong] = true;
        }
        if (is_array($pool->excluded_ips)) {
            foreach ($pool->excluded_ips as $excludedIp) {
                if ($excludedIp === '') {
                    continue;
                }
                $exLong = CidrHelper::ipToUnsigned($excludedIp);
                if ($exLong !== null && $exLong >= $firstLong && $exLong <= $lastLong) {
                    $excludedSet[$exLong] = true;
                }
            }
        }

        $allocations = $pool->allocations()
            ->with(['virtualMachine:id,name,status', 'user:id,name,email'])
            ->get();

        /** @var array<string, IpAllocation> $allocationsByIp */
        $allocationsByIp = [];
        foreach ($allocations as $allocation) {
            $allocationsByIp[$allocation->ip_string] = $allocation;
        }

        $nonFreeMap = [];

        foreach ($allocationsByIp as $ipString => $allocation) {
            $ipLong = CidrHelper::ipToUnsigned($ipString);
            if ($ipLong === null || $ipLong < $firstLong || $ipLong > $lastLong) {
                continue;
            }

            $isGateway = $gatewayLong !== null && $ipLong === $gatewayLong;

            if ($allocation->status === 'allocated') {
                $nonFreeMap[$ipLong] = $this->makePoolAddressEntry(
                    $ipString,
                    'allocated',
                    $isGateway,
                    0,
                    $this->formatPoolAllocation($allocation),
                );
            } elseif ($allocation->status === 'cooling' && $allocation->released_at !== null) {
                $coolingUntil = $allocation->released_at->copy()->addSeconds($pool->cooldown_seconds);
                if ($coolingUntil->greaterThan($now)) {
                    $diff = max(1, (int) ceil($now->diffInSeconds($coolingUntil, false)));
                    $nonFreeMap[$ipLong] = $this->makePoolAddressEntry(
                        $ipString,
                        'cooling',
                        $isGateway,
                        $diff,
                        $this->formatPoolAllocation($allocation),
                    );
                }
            }
        }

        foreach (array_keys($excludedSet) as $excludedLong) {
            if (isset($nonFreeMap[$excludedLong])) {
                continue;
            }

            $isGateway = $gatewayLong !== null && $excludedLong === $gatewayLong;
            $nonFreeMap[$excludedLong] = $this->makePoolAddressEntry(
                long2ip($excludedLong),
                'excluded',
                $isGateway,
                0,
                null,
            );
        }

        ksort($nonFreeMap, SORT_NUMERIC);

        return $nonFreeMap;
    }

    /**
     * Slice a page of addresses across the full pool usable range without materializing all IPs.
     *
     * @param  array<int, array{
     *     ip: string,
     *     state: 'free'|'allocated'|'cooling'|'excluded',
     *     is_gateway: bool,
     *     cooldown_remaining_seconds: int,
     *     allocation: array{
     *         id: int,
     *         mac_address: string|null,
     *         status: string,
     *         allocated_at: string|null,
     *         released_at: string|null,
     *         virtual_machine: array{id: int, name: string, status: string}|null,
     *         user: array{id: int, name: string, email: string}|null,
     *     }|null,
     * }>  $nonFreeMap
     * @return list<array{
     *     ip: string,
     *     state: 'free'|'allocated'|'cooling'|'excluded',
     *     is_gateway: bool,
     *     cooldown_remaining_seconds: int,
     *     allocation: array{
     *         id: int,
     *         mac_address: string|null,
     *         status: string,
     *         allocated_at: string|null,
     *         released_at: string|null,
     *         virtual_machine: array{id: int, name: string, status: string}|null,
     *         user: array{id: int, name: string, email: string}|null,
     *     }|null,
     * }>
     */
    private function sliceAllPoolAddresses(
        int $firstLong,
        int $lastLong,
        array $nonFreeMap,
        int $offset,
        int $perPage,
    ): array {
        $total = ($lastLong - $firstLong) + 1;
        if ($offset >= $total) {
            return [];
        }

        $startLong = $firstLong + $offset;
        $endLong = min($lastLong, $startLong + $perPage - 1);
        $items = [];

        for ($ipLong = $startLong; $ipLong <= $endLong; $ipLong++) {
            $items[] = $nonFreeMap[$ipLong] ?? $this->makePoolAddressEntry(long2ip($ipLong), 'free', false, 0, null);
        }

        return $items;
    }

    /**
     * Slice a page of free addresses using gap-jumping over sorted non-free unsigned IPs.
     *
     * @param  list<int>  $nonFreeLongs
     * @return list<array{
     *     ip: string,
     *     state: 'free'|'allocated'|'cooling'|'excluded',
     *     is_gateway: bool,
     *     cooldown_remaining_seconds: int,
     *     allocation: array{
     *         id: int,
     *         mac_address: string|null,
     *         status: string,
     *         allocated_at: string|null,
     *         released_at: string|null,
     *         virtual_machine: array{id: int, name: string, status: string}|null,
     *         user: array{id: int, name: string, email: string}|null,
     *     }|null,
     * }>
     */
    private function sliceFreePoolAddresses(
        int $firstLong,
        int $lastLong,
        array $nonFreeLongs,
        int $totalFree,
        int $offset,
        int $perPage,
    ): array {
        if ($offset >= $totalFree) {
            return [];
        }

        $items = [];
        $cursor = $firstLong;
        $skip = $offset;
        $needed = min($perPage, $totalFree - $offset);
        $nonFreeIdx = 0;
        $nonFreeCount = count($nonFreeLongs);

        while ($cursor <= $lastLong && $skip > 0) {
            if ($nonFreeIdx >= $nonFreeCount) {
                $cursor += $skip;
                $skip = 0;
                break;
            }

            $blocked = $nonFreeLongs[$nonFreeIdx];
            if ($blocked < $cursor) {
                $nonFreeIdx++;

                continue;
            }
            if ($blocked === $cursor) {
                $cursor++;
                $nonFreeIdx++;

                continue;
            }

            $gapSize = $blocked - $cursor;
            if ($skip < $gapSize) {
                $cursor += $skip;
                $skip = 0;
                break;
            }

            $skip -= $gapSize;
            $cursor = $blocked + 1;
            $nonFreeIdx++;
        }

        while ($cursor <= $lastLong && count($items) < $needed) {
            if ($nonFreeIdx < $nonFreeCount) {
                $blocked = $nonFreeLongs[$nonFreeIdx];
                if ($blocked < $cursor) {
                    $nonFreeIdx++;

                    continue;
                }
                if ($blocked === $cursor) {
                    $cursor++;
                    $nonFreeIdx++;

                    continue;
                }
                $runEnd = min($lastLong, $blocked - 1);
            } else {
                $runEnd = $lastLong;
            }

            $take = min($needed - count($items), ($runEnd - $cursor) + 1);
            for ($i = 0; $i < $take; $i++) {
                $items[] = $this->makePoolAddressEntry(long2ip($cursor + $i), 'free', false, 0, null);
            }
            $cursor += $take;
        }

        return $items;
    }

    /**
     * Narrow a usable unsigned IP range using complete leading IPv4 octets from a prefix search.
     *
     * @return array{0: int, 1: int}
     */
    private function narrowPrefixSearchRange(string $search, int $firstLong, int $lastLong): array
    {
        if (preg_match('/^[0-9.]+$/', $search) !== 1) {
            return [1, 0];
        }

        $parts = explode('.', $search);
        if (count($parts) > 4) {
            return [1, 0];
        }

        $completeSegments = array_slice($parts, 0, -1);
        $completeCount = count($completeSegments);
        $base = 0;

        foreach ($completeSegments as $index => $segment) {
            if ($segment === '' || ! ctype_digit($segment) || (strlen($segment) > 1 && $segment[0] === '0')) {
                return [1, 0];
            }

            $octet = (int) $segment;
            if ($octet > 255) {
                return [1, 0];
            }

            $base |= ($octet << (24 - ($index * 8)));
        }

        $lastSegment = end($parts);
        if ($lastSegment !== '') {
            if (! ctype_digit($lastSegment) || (strlen($lastSegment) > 1 && $lastSegment[0] === '0') || (int) $lastSegment > 255) {
                return [1, 0];
            }
        }

        $remainingBits = 32 - ($completeCount * 8);
        $suffixMask = $remainingBits === 32 ? 0xFFFFFFFF : ((1 << $remainingBits) - 1);

        return [max($firstLong, $base), min($lastLong, $base | $suffixMask)];
    }

    /**
     * Format an IpAllocation record for the pool addresses endpoint.
     *
     * @return array{
     *     id: int,
     *     mac_address: string|null,
     *     status: string,
     *     allocated_at: string|null,
     *     released_at: string|null,
     *     virtual_machine: array{id: int, name: string, status: string}|null,
     *     user: array{id: int, name: string, email: string}|null,
     * }
     */
    private function formatPoolAllocation(IpAllocation $allocation): array
    {
        return [
            'id' => $allocation->id,
            'mac_address' => $allocation->mac_address,
            'status' => $allocation->status,
            'allocated_at' => $allocation->allocated_at->toISOString(),
            'released_at' => $allocation->released_at?->toISOString(),
            'virtual_machine' => $allocation->virtualMachine !== null ? [
                'id' => $allocation->virtualMachine->id,
                'name' => $allocation->virtualMachine->name,
                'status' => (string) $allocation->virtualMachine->status,
            ] : null,
            'user' => [
                'id' => $allocation->user->id,
                'name' => $allocation->user->name,
                'email' => $allocation->user->email,
            ],
        ];
    }

    /**
     * Build a single pool address item entry.
     *
     * @param  'free'|'allocated'|'cooling'|'excluded'  $state
     * @param  array{
     *     id: int,
     *     mac_address: string|null,
     *     status: string,
     *     allocated_at: string|null,
     *     released_at: string|null,
     *     virtual_machine: array{id: int, name: string, status: string}|null,
     *     user: array{id: int, name: string, email: string}|null,
     * }|null  $allocation
     * @return array{
     *     ip: string,
     *     state: 'free'|'allocated'|'cooling'|'excluded',
     *     is_gateway: bool,
     *     cooldown_remaining_seconds: int,
     *     allocation: array{
     *         id: int,
     *         mac_address: string|null,
     *         status: string,
     *         allocated_at: string|null,
     *         released_at: string|null,
     *         virtual_machine: array{id: int, name: string, status: string}|null,
     *         user: array{id: int, name: string, email: string}|null,
     *     }|null,
     * }
     */
    private function makePoolAddressEntry(
        string $ip,
        string $state,
        bool $isGateway,
        int $cooldownRemainingSeconds,
        ?array $allocation,
    ): array {
        return [
            'ip' => $ip,
            'state' => $state,
            'is_gateway' => $isGateway,
            'cooldown_remaining_seconds' => $cooldownRemainingSeconds,
            'allocation' => $allocation,
        ];
    }

    /**
     * Build pagination metadata matching standard Laravel paginator responses.
     *
     * @return array{
     *     current_page: int,
     *     last_page: int,
     *     per_page: int,
     *     total: int,
     *     from: int|null,
     *     to: int|null,
     * }
     */
    private function buildPoolAddressPaginationMeta(int $total, int $page, int $perPage, int $countOnPage): array
    {
        $offset = ($page - 1) * $perPage;

        return [
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'per_page' => $perPage,
            'total' => $total,
            'from' => $countOnPage > 0 ? $offset + 1 : null,
            'to' => $countOnPage > 0 ? $offset + $countOnPage : null,
        ];
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
            }

            if (array_key_exists('parent_interface', $data)) {
                $fieldsToUpdate['parent_interface'] = is_string($data['parent_interface']) ? $data['parent_interface'] : '';
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
        /** @var array<string, array{type: string|null, managed: bool}> $networks */
        $networks = [];
        try {
            $gateway = app(HypervisorGateway::class);
            $client = $gateway->client($pool->host);
            $res = $client->withQueryParameters(['recursion' => 1])->get('/1.0/networks');

            if ($res->successful()) {
                $hostReachable = true;
                $metadata = $res->json('metadata') ?? [];
                if (is_array($metadata)) {
                    foreach ($metadata as $item) {
                        if (is_string($item)) {
                            $name = basename($item);
                            if ($name !== '') {
                                $networks[$name] = ['type' => null, 'managed' => false];
                            }
                        } elseif (is_array($item) && isset($item['name']) && is_string($item['name'])) {
                            $name = $item['name'];
                            if ($name !== '') {
                                $rawType = isset($item['type']) && is_string($item['type']) ? trim($item['type']) : '';
                                $type = ($rawType !== '' && $rawType !== 'unknown') ? $rawType : null;
                                $managed = isset($item['managed']) && (bool) $item['managed'];
                                $networks[$name] = ['type' => $type, 'managed' => $managed];
                            }
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

        $networkNames = array_keys($networks);
        $networkMode = $pool->network_mode !== '' ? $pool->network_mode : 'routed';
        $isManual = ($networkMode === 'manual');
        $parentInterface = trim($pool->parent_interface);

        // 2. Parent interface existence on host
        if ($isManual) {
            $checks[] = [
                'name' => 'Parent interface exists',
                'ok' => true,
                'hint' => 'Parent interface is not used in manual network mode.',
            ];
        } elseif ($parentInterface === '') {
            $overallOk = false;
            $checks[] = [
                'name' => 'Parent interface exists',
                'ok' => false,
                'hint' => sprintf("Parent interface is required for '%s' network mode and is currently empty.", $networkMode),
            ];
        } elseif (! $hostReachable) {
            $overallOk = false;
            $checks[] = [
                'name' => 'Parent interface exists',
                'ok' => false,
                'hint' => sprintf("Cannot verify parent interface '%s' because host is unreachable.", $parentInterface),
            ];
        } else {
            if (array_key_exists($parentInterface, $networks)) {
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

        // 3. Parent interface type compatibility with network mode
        $typeCheck = $this->evaluateParentInterfaceTypeCheck(
            $networkMode,
            $parentInterface,
            $hostReachable,
            $networks[$parentInterface] ?? null,
        );
        if (! $typeCheck['ok']) {
            $overallOk = false;
        }
        $checks[] = $typeCheck;

        // 4. Pool range consistent with CIDR (and gateway for non-bridged/macvlan modes)
        $requiresGateway = in_array($networkMode, ['bridged', 'macvlan'], true);
        $gatewayIp = $pool->gateway !== null ? trim($pool->gateway) : '';
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
            if (! $requiresGateway && ! empty($pool->gateway)) {
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
                'hint' => $requiresGateway
                    ? 'Usable IP range is consistent with CIDR block.'
                    : 'Usable IP range and gateway are consistent with CIDR block.',
            ];
        } else {
            $overallOk = false;
            $checks[] = [
                'name' => 'Pool range consistent',
                'ok' => false,
                'hint' => implode(' ', $rangeHints),
            ];
        }

        // 5. Gateway configured (required for bridged and macvlan modes)
        if ($requiresGateway) {
            if ($gatewayIp === '') {
                $overallOk = false;
                $checks[] = [
                    'name' => 'Gateway configured',
                    'ok' => false,
                    'hint' => sprintf("A gateway IP is required for '%s' network mode so instances receive a default route.", $networkMode),
                ];
            } elseif ($range === null) {
                $overallOk = false;
                $checks[] = [
                    'name' => 'Gateway configured',
                    'ok' => false,
                    'hint' => sprintf("Cannot validate gateway IP '%s' against invalid CIDR '%s'.", $gatewayIp, $pool->cidr),
                ];
            } else {
                [$network, $broadcast] = $range;
                $gwLong = CidrHelper::ipToUnsigned($gatewayIp);
                if ($gwLong === null || $gwLong < $network || $gwLong > $broadcast) {
                    $overallOk = false;
                    $checks[] = [
                        'name' => 'Gateway configured',
                        'ok' => false,
                        'hint' => sprintf("Gateway IP '%s' is outside CIDR %s.", $gatewayIp, $pool->cidr),
                    ];
                } else {
                    $checks[] = [
                        'name' => 'Gateway configured',
                        'ok' => true,
                        'hint' => sprintf("Gateway IP '%s' is configured and within CIDR %s.", $gatewayIp, $pool->cidr),
                    ];
                }
            }
        }

        // 6. Host kernel routing & proxy ARP hint
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
     * Evaluate whether the discovered host interface type matches the pool's network mode.
     *
     * @param  array{type: string|null, managed: bool}|null  $interfaceInfo
     * @return array{name: string, ok: bool, hint: string}
     */
    private function evaluateParentInterfaceTypeCheck(
        string $networkMode,
        string $parentInterface,
        bool $hostReachable,
        ?array $interfaceInfo,
    ): array {
        $checkName = 'Parent interface type matches mode';

        if ($networkMode === 'manual') {
            return [
                'name' => $checkName,
                'ok' => true,
                'hint' => 'Parent interface type check is not applicable in manual network mode.',
            ];
        }

        if ($parentInterface === '') {
            return [
                'name' => $checkName,
                'ok' => false,
                'hint' => sprintf("Cannot verify parent interface type because parent_interface is empty for '%s' network mode.", $networkMode),
            ];
        }

        if (! $hostReachable) {
            return [
                'name' => $checkName,
                'ok' => false,
                'hint' => sprintf("Cannot verify parent interface '%s' type because host is unreachable.", $parentInterface),
            ];
        }

        if ($interfaceInfo === null) {
            return [
                'name' => $checkName,
                'ok' => false,
                'hint' => sprintf("Cannot verify interface type because parent interface '%s' was not found on the host.", $parentInterface),
            ];
        }

        $type = $interfaceInfo['type'];
        if ($type === null) {
            return [
                'name' => $checkName,
                'ok' => true,
                'hint' => sprintf("Interface type for '%s' could not be verified from hypervisor response; ensure it matches '%s' mode.", $parentInterface, $networkMode),
            ];
        }

        if ($networkMode === 'bridged') {
            if ($type === 'bridge') {
                $bridgeKind = $interfaceInfo['managed'] ? 'an Incus managed bridge' : 'a Linux bridge';

                return [
                    'name' => $checkName,
                    'ok' => true,
                    'hint' => sprintf("Parent interface '%s' is %s (type 'bridge'), compatible with 'bridged' mode.", $parentInterface, $bridgeKind),
                ];
            }

            $recommendation = in_array($type, ['physical', 'bond', 'vlan'], true)
                ? sprintf("Use 'macvlan' or 'routed' mode for '%s' interfaces, or select a 'bridge' interface.", $type)
                : "Select a 'bridge' interface for 'bridged' mode.";

            return [
                'name' => $checkName,
                'ok' => false,
                'hint' => sprintf("Parent interface '%s' has type '%s', which is incompatible with 'bridged' mode (requires 'bridge'). %s", $parentInterface, $type, $recommendation),
            ];
        }

        if ($networkMode === 'macvlan') {
            if (in_array($type, ['physical', 'bond', 'vlan'], true)) {
                return [
                    'name' => $checkName,
                    'ok' => true,
                    'hint' => sprintf("Parent interface '%s' has type '%s', compatible with 'macvlan' mode.", $parentInterface, $type),
                ];
            }

            $recommendation = $type === 'bridge'
                ? "Use 'bridged' or 'routed' mode for 'bridge' interfaces, or select a 'physical', 'bond', or 'vlan' interface."
                : "Select a 'physical', 'bond', or 'vlan' interface for 'macvlan' mode.";

            return [
                'name' => $checkName,
                'ok' => false,
                'hint' => sprintf("Parent interface '%s' has type '%s', which is incompatible with 'macvlan' mode (requires 'physical', 'bond', or 'vlan'). %s", $parentInterface, $type, $recommendation),
            ];
        }

        if (in_array($type, ['physical', 'bond', 'vlan', 'bridge'], true)) {
            return [
                'name' => $checkName,
                'ok' => true,
                'hint' => sprintf("Parent interface '%s' has type '%s', which is supported in 'routed' mode.", $parentInterface, $type),
            ];
        }

        return [
            'name' => $checkName,
            'ok' => true,
            'hint' => sprintf("Parent interface '%s' reports type '%s'. Routed mode typically uses 'physical', 'bond', 'vlan', or 'bridge' interfaces; verify host routing.", $parentInterface, $type),
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
