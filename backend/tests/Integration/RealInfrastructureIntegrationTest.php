<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\IpPoolExhaustedException;
use App\Jobs\CreateSnapshotJob;
use App\Jobs\DeleteSnapshotJob;
use App\Jobs\DeleteUserWorkloadsJob;
use App\Jobs\ExecuteVmActionJob;
use App\Jobs\ProvisionVmJob;
use App\Jobs\RestoreSnapshotJob;
use App\Jobs\SuspendUserWorkloadsJob;
use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Models\VmSnapshot;
use App\Services\IpManagementService;
use App\Services\VmSnapshotService;
use App\States\Vm\Deleted;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;

test('parallel IP allocation under concurrent processes', function (): void {
    skipUnlessRealInfrastructure();

    $host = Host::create([
        'name' => 'parallel-test-host-'.uniqid(),
        'endpoint_url' => 'https://127.0.0.1:8443',
        'driver' => 'fake',
        'is_active' => true,
    ]);

    $pool = IpPool::create([
        'host_id' => $host->id,
        'name' => 'Parallel IP Pool '.uniqid(),
        'cidr' => '10.99.0.0/24',
        'first_usable' => '10.99.0.10',
        'last_usable' => '10.99.0.250',
        'network_mode' => 'routed',
        'parent_interface' => 'eth0',
        'active' => true,
        'is_public' => true,
    ]);

    $user = User::factory()->create();

    /** @var list<VirtualMachine> $vms */
    $vms = [];
    for ($i = 1; $i <= 8; $i++) {
        $vms[] = VirtualMachine::create([
            'user_id' => $user->id,
            'host_id' => $host->id,
            'name' => sprintf('par-vm-%s-%d', uniqid(), $i),
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'running',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);
    }

    try {
        $artisan = base_path('artisan');
        $php = PHP_BINARY ?: 'php';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cleanEnv = [];
        foreach (array_merge(getenv(), $_ENV, $_SERVER) as $k => $v) {
            if (is_scalar($v)) {
                $cleanEnv[(string) $k] = (string) $v;
            }
        }

        $env = array_merge($cleanEnv, [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) config('database.connections.mysql.host', '127.0.0.1'),
            'DB_PORT' => (string) config('database.connections.mysql.port', 3306),
            'DB_DATABASE' => (string) config('database.connections.mysql.database', 'ognivo_test'),
            'DB_USERNAME' => (string) config('database.connections.mysql.username', 'root'),
            'DB_PASSWORD' => (string) (config('database.connections.mysql.password') ?? ''),
            'QUEUE_CONNECTION' => 'redis',
            'CACHE_STORE' => 'redis',
            'REDIS_HOST' => (string) config('database.redis.default.host', '127.0.0.1'),
            'REDIS_PORT' => (string) config('database.redis.default.port', 6379),
            'REDIS_CLIENT' => (string) env('REDIS_CLIENT', 'predis'),
        ]);

        /** @var list<array{process: resource, pipes: array<int, resource>, vm: VirtualMachine}> $processes */
        $processes = [];

        foreach ($vms as $vm) {
            $cmd = [$php, $artisan, 'tests:allocate-ip', (string) $pool->id, (string) $vm->id, '--env=testing'];
            $proc = proc_open($cmd, $descriptors, $pipes, base_path(), $env);

            if (! is_resource($proc)) {
                test()->fail(sprintf('Failed to spawn OS subprocess for VM #%d', $vm->id));
            }

            $processes[] = [
                'process' => $proc,
                'pipes' => $pipes,
                'vm' => $vm,
            ];
        }

        $allocatedIps = [];
        $errors = [];

        foreach ($processes as $item) {
            $pipes = $item['pipes'];
            fclose($pipes[0]);

            $stdout = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $exitCode = proc_close($item['process']);

            if ($exitCode !== 0 || trim($stderr) !== '') {
                $errors[] = sprintf(
                    'VM #%d process exit %d. stdout: "%s", stderr: "%s"',
                    $item['vm']->id,
                    $exitCode,
                    trim($stdout),
                    trim($stderr)
                );
            } else {
                $ip = trim($stdout);
                if ($ip !== '') {
                    $allocatedIps[] = $ip;
                }
            }
        }

        // 1. Zero exceptions across all processes
        if (! empty($errors)) {
            test()->fail("Errors encountered in subprocesses:\n".implode("\n", $errors));
        }
        expect($errors)->toBeEmpty();

        // 2. N=8 distinct IPs returned
        expect($allocatedIps)->toHaveCount(8);
        expect(array_unique($allocatedIps))->toHaveCount(8);

        // 3. Database consistency & UNIQUE(pool_id, address) constraint
        $allocations = IpAllocation::where('pool_id', $pool->id)->get();
        expect($allocations)->toHaveCount(8);

        $addresses = $allocations->pluck('address')->all();
        expect(array_unique($addresses))->toHaveCount(8);

        $ipStrings = $allocations->pluck('ip_string')->all();
        expect(array_unique($ipStrings))->toHaveCount(8);

        // 4. Each VM has its IP persisted
        foreach ($vms as $vm) {
            $freshVm = $vm->fresh();
            expect($freshVm)->not->toBeNull();
            expect($freshVm->ipv4_address)->not->toBeNull();
            expect($freshVm->network_status)->toBe('assigned');
        }
    } finally {
        IpAllocation::where('pool_id', $pool->id)->delete();
        foreach ($vms as $vm) {
            $vm->delete();
        }
        $pool->delete();
        $host->delete();
        $user->delete();
    }
})->group('integration');

test('queue config sanity: REDIS retry_after is strictly greater than largest job timeout', function (): void {
    skipUnlessRealInfrastructure();

    $jobs = [
        DeleteUserWorkloadsJob::class,
        ExecuteVmActionJob::class,
        ProvisionVmJob::class,
        SuspendUserWorkloadsJob::class,
        CreateSnapshotJob::class,
        RestoreSnapshotJob::class,
        DeleteSnapshotJob::class,
    ];

    $maxTimeout = 0;
    foreach ($jobs as $jobClass) {
        $reflection = new ReflectionClass($jobClass);
        if ($reflection->hasProperty('timeout')) {
            $timeoutVal = $reflection->getProperty('timeout')->getDefaultValue();
            if (is_int($timeoutVal) && $timeoutVal > $maxTimeout) {
                $maxTimeout = $timeoutVal;
            }
        }
    }

    $retryAfter = (int) config('queue.connections.redis.retry_after');

    expect($maxTimeout)->toBeGreaterThan(0);
    expect($retryAfter)->toBeGreaterThan($maxTimeout);
})->group('integration');

test('delete VM with allocation through a real queue worker', function (): void {
    skipUnlessRealInfrastructure();

    $host = Host::create([
        'name' => 'worker-test-host-'.uniqid(),
        'endpoint_url' => 'https://127.0.0.1:8443',
        'driver' => 'fake',
        'is_active' => true,
    ]);

    $pool = IpPool::create([
        'host_id' => $host->id,
        'name' => 'Single IP Pool '.uniqid(),
        'cidr' => '10.88.0.0/24',
        'first_usable' => '10.88.0.50',
        'last_usable' => '10.88.0.50',
        'network_mode' => 'routed',
        'parent_interface' => 'eth0',
        'active' => true,
        'is_public' => true,
        'cooldown_seconds' => 300,
    ]);

    $user = User::factory()->create();

    $vm = VirtualMachine::create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'name' => 'worker-vm-'.uniqid(),
        'incus_project' => 'default',
        'instance_type' => 'virtual-machine',
        'status' => Running::class,
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'os_image' => 'ubuntu:24.04',
    ]);

    $anotherVm = null;

    try {
        $ipService = app(IpManagementService::class);
        $allocation = $ipService->allocateIp($pool, $vm, $user);

        // Initial assertions: 1 IP allocated, VM has assigned network status
        expect($allocation->status)->toBe('allocated');
        expect(IpAllocation::where('user_id', $user->id)->where('status', 'allocated')->count())->toBe(1);
        expect($vm->fresh()->ipv4_address)->toBe('10.88.0.50');
        expect($vm->fresh()->network_status)->toBe('assigned');

        // Dispatch delete action to the queue
        ExecuteVmActionJob::dispatch($vm, 'delete');

        // Run real queue worker until empty
        $exitCode = runWorkerUntilEmpty(10);
        expect($exitCode)->toBe(0);

        // Assertions after worker completed
        $freshVm = $vm->fresh();
        expect($freshVm->status->equals(Deleted::class))->toBeTrue();
        expect($freshVm->ipv4_address)->toBeNull();
        expect($freshVm->network_status)->toBe('unassigned');

        $freshAllocation = $allocation->fresh();
        expect($freshAllocation->status)->toBe('cooling');
        expect($freshAllocation->released_at)->not->toBeNull();
        expect($freshAllocation->user_id)->toBe($user->id);
        expect($freshAllocation->virtual_machine_id)->toBeNull();

        // IP quota usage dropped by one
        expect(IpAllocation::where('user_id', $user->id)->where('status', 'allocated')->count())->toBe(0);

        // The same IP is not reallocated before the pool's cooldown expires
        $anotherVm = VirtualMachine::create([
            'user_id' => $user->id,
            'host_id' => $host->id,
            'name' => 'another-vm-'.uniqid(),
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        expect(fn () => $ipService->allocateIp($pool, $anotherVm, $user))
            ->toThrow(IpPoolExhaustedException::class);

        // Simulating cooldown expiration makes the IP reallocatable
        $freshAllocation->update(['released_at' => now()->subSeconds(301)]);

        $newAllocation = $ipService->allocateIp($pool, $anotherVm, $user);
        expect($newAllocation->ip_string)->toBe('10.88.0.50');
        expect($newAllocation->status)->toBe('allocated');
        expect($anotherVm->fresh()->ipv4_address)->toBe('10.88.0.50');
    } finally {
        IpAllocation::where('pool_id', $pool->id)->delete();
        $vm->delete();
        if ($anotherVm !== null) {
            $anotherVm->delete();
        }
        $pool->delete();
        $host->delete();
        $user->delete();
    }
})->group('integration');

test('provisioning with empty network state through a real queue worker', function (): void {
    skipUnlessRealInfrastructure();

    $host = Host::create([
        'name' => 'worker-prov-host-'.uniqid(),
        'endpoint_url' => 'https://127.0.0.1:8443',
        'driver' => 'fake',
        'is_active' => true,
    ]);

    $pool = IpPool::create([
        'host_id' => $host->id,
        'name' => 'Prov IP Pool '.uniqid(),
        'cidr' => '10.77.0.0/24',
        'first_usable' => '10.77.0.50',
        'last_usable' => '10.77.0.60',
        'network_mode' => 'routed',
        'parent_interface' => 'eth0',
        'active' => true,
        'is_public' => true,
    ]);

    $user = User::factory()->create();

    $vm = VirtualMachine::create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'name' => 'worker-prov-vm-'.uniqid(),
        'incus_project' => 'default',
        'instance_type' => 'virtual-machine',
        'status' => 'provisioning',
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'os_image' => 'ubuntu:24.04',
    ]);

    try {
        $ipService = app(IpManagementService::class);
        $allocation = $ipService->allocateIp($pool, $vm, $user);

        // Configure fake hypervisor driver to return empty network state forever
        Cache::put("fake_driver:state:{$vm->name}", [
            'status' => 'Running',
            'network' => [],
        ], 3600);

        putenv('PROVISION_NETWORK_WAIT_SECONDS=5');
        putenv('PROVISION_POLL_INTERVAL_SECONDS=1');
        $_ENV['PROVISION_NETWORK_WAIT_SECONDS'] = '5';
        $_ENV['PROVISION_POLL_INTERVAL_SECONDS'] = '1';
        config([
            'provisioning.network_wait_seconds' => 5,
            'provisioning.poll_interval_seconds' => 1,
        ]);

        ProvisionVmJob::dispatch($vm, $allocation->id);

        $startTime = time();
        while (time() - $startTime < 15) {
            runWorkerUntilEmpty(10);
            $fresh = $vm->fresh();
            if ($fresh->status->equals(Running::class) && $fresh->network_status === 'unverified') {
                break;
            }
            sleep(1);
        }

        $freshVm = $vm->fresh();
        expect($freshVm->status->equals(Running::class))->toBeTrue();
        expect($freshVm->network_status)->toBe('unverified');
    } finally {
        IpAllocation::where('pool_id', $pool->id)->delete();
        $vm->delete();
        $pool->delete();
        $host->delete();
        $user->delete();
    }
})->group('integration');

test('snapshot create, restore and delete flow through a real queue worker', function (): void {
    skipUnlessRealInfrastructure();

    $host = Host::create([
        'name' => 'snap-worker-host-'.uniqid(),
        'endpoint_url' => 'https://127.0.0.1:8443',
        'driver' => 'fake',
        'is_active' => true,
    ]);

    $user = User::factory()->create();

    $vm = VirtualMachine::create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'name' => 'snap-worker-vm-'.uniqid(),
        'incus_project' => 'default',
        'instance_type' => 'virtual-machine',
        'status' => Stopped::class,
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'os_image' => 'ubuntu:24.04',
    ]);

    $snapshotService = app(VmSnapshotService::class);
    $snapshot = null;

    try {
        // 1. Create snapshot via service -> status is creating, CreateSnapshotJob is queued
        $snapshot = $snapshotService->create($vm, 'integration-snap-1');
        expect($snapshot->status)->toBe(VmSnapshot::STATUS_CREATING);
        expect(VmSnapshot::where('id', $snapshot->id)->exists())->toBeTrue();

        $exitCode = runWorkerUntilEmpty(10);
        expect($exitCode)->toBe(0);

        $freshSnap = $snapshot->fresh();
        expect($freshSnap)->not->toBeNull();
        expect($freshSnap->status)->toBe(VmSnapshot::STATUS_READY);
        expect($freshSnap->error)->toBeNull();

        // 2. Restore snapshot via service -> status is restoring, RestoreSnapshotJob is queued
        $snapshotService->restore($freshSnap);
        expect($freshSnap->fresh()->status)->toBe(VmSnapshot::STATUS_RESTORING);

        $exitCode = runWorkerUntilEmpty(10);
        expect($exitCode)->toBe(0);

        $restoredSnap = $freshSnap->fresh();
        expect($restoredSnap)->not->toBeNull();
        expect($restoredSnap->status)->toBe(VmSnapshot::STATUS_READY);
        expect($restoredSnap->error)->toBeNull();

        // 3. Delete snapshot via service -> status is deleting, DeleteSnapshotJob is queued
        $snapshotService->delete($restoredSnap);
        expect($restoredSnap->fresh()->status)->toBe(VmSnapshot::STATUS_DELETING);

        $exitCode = runWorkerUntilEmpty(10);
        expect($exitCode)->toBe(0);

        expect(VmSnapshot::find($snapshot->id))->toBeNull();
    } finally {
        if ($snapshot !== null) {
            VmSnapshot::where('id', $snapshot->id)->delete();
        }
        $vm->delete();
        $host->delete();
        $user->delete();
    }
})->group('integration');
