<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Host;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Models\VpsPlan;
use App\Models\VpsPlanChange;
use App\Models\VpsPlanVersion;
use App\Services\Vps\ResourceReservationService;
use App\Services\Vps\VpsPlanVersionService;
use App\States\Host\Ok as HostOk;
use App\States\Vm\Running as VmRunning;
use App\States\VpsPlan\Draft as PlanDraft;
use App\States\VpsPlan\Published as PlanPublished;
use App\States\VpsPlanChange\Applying;
use App\States\VpsPlanChange\AwaitingAuthorization;
use App\States\VpsPlanChange\Cancelled;
use App\States\VpsPlanChange\Completed;
use App\States\VpsPlanChange\Failed;
use App\States\VpsPlanChange\Pending;
use App\States\VpsPlanChange\ReconciliationRequired;
use App\States\VpsPlanVersion\Published as VersionPublished;
use App\States\VpsPlanVersion\Superseded as VersionSuperseded;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

uses()->group('integration');

beforeEach(function (): void {
    skipUnlessRealInfrastructure();
});

if (! function_exists('Tests\Integration\vpsPlanConcurrencyBuildEnv')) {
    /**
     * Build environment variables for child PHP processes so they target the exact
     * same MySQL database and Redis instance as the parent integration test process.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    function vpsPlanConcurrencyBuildEnv(array $overrides = []): array
    {
        $cleanEnv = [];
        foreach (array_merge(getenv(), $_ENV, $_SERVER) as $key => $value) {
            if (is_scalar($value)) {
                $cleanEnv[(string) $key] = (string) $value;
            }
        }

        return array_merge($cleanEnv, [
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
            'REDIS_PASSWORD' => (string) (config('database.redis.default.password') ?? ''),
            'REDIS_CLIENT' => (string) env('REDIS_CLIENT', 'predis'),
        ], $overrides);
    }
}

if (! function_exists('Tests\Integration\vpsPlanConcurrencyRunWorkers')) {
    /**
     * Spawn parallel PHP subprocesses synchronized at a Redis barrier before executing `$workerBody`.
     *
     * @param  list<array<string, mixed>>  $workerPayloads
     * @param  array<string, string>  $envOverrides
     * @return list<array{exit: int, stdout: string, stderr: string, json: array<string, mixed>|null}>
     */
    function vpsPlanConcurrencyRunWorkers(
        array $workerPayloads,
        string $workerBody,
        array $envOverrides = [],
    ): array {
        $php = PHP_BINARY ?: 'php';
        $env = vpsPlanConcurrencyBuildEnv($envOverrides);
        $barrierKey = 'test:vps_plan_barrier:'.uniqid('', true);
        $workerCount = count($workerPayloads);

        Redis::connection()->del($barrierKey);

        $fullScript = <<<PHP
require 'vendor/autoload.php';
\$app = require 'bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

\$payload = json_decode((string) (\$argv[1] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
\$barrierKey = (string) (\$payload['barrier_key'] ?? '');
\$expectedWorkers = (int) (\$payload['expected_workers'] ?? 1);

if (\$barrierKey !== '' && \$expectedWorkers > 1) {
    \$redis = Illuminate\Support\Facades\Redis::connection();
    \$redis->incr(\$barrierKey);
    \$redis->expire(\$barrierKey, 30);
    \$deadline = microtime(true) + 5.0;
    while ((int) \$redis->get(\$barrierKey) < \$expectedWorkers) {
        if (microtime(true) > \$deadline) {
            fwrite(STDERR, "Barrier timeout\\n");
            exit(2);
        }
        usleep(1000);
    }
}

{$workerBody}
PHP;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        /** @var list<array{process: resource, pipes: array<int, resource>}> $processes */
        $processes = [];

        try {
            foreach ($workerPayloads as $payload) {
                $encodedPayload = json_encode(array_merge($payload, [
                    'barrier_key' => $barrierKey,
                    'expected_workers' => $workerCount,
                ]), JSON_THROW_ON_ERROR);

                $proc = proc_open(
                    [$php, '-r', $fullScript, '--', $encodedPayload],
                    $descriptors,
                    $pipes,
                    base_path(),
                    $env,
                );

                expect(is_resource($proc))->toBeTrue();

                $processes[] = [
                    'process' => $proc,
                    'pipes' => $pipes,
                ];
            }

            $results = [];
            foreach ($processes as $item) {
                $pipes = $item['pipes'];
                fclose($pipes[0]);

                $stdout = (string) stream_get_contents($pipes[1]);
                fclose($pipes[1]);

                $stderr = (string) stream_get_contents($pipes[2]);
                fclose($pipes[2]);

                $exitCode = proc_close($item['process']);
                $decoded = json_decode(trim($stdout), true);

                $results[] = [
                    'exit' => $exitCode,
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                    'json' => is_array($decoded) ? $decoded : null,
                ];
            }

            return $results;
        } finally {
            Redis::connection()->del($barrierKey);
        }
    }
}

if (! function_exists('Tests\Integration\vpsPlanConcurrencyCleanup')) {
    /**
     * Remove test rows in foreign-key-safe order without invoking immutable-version model guards.
     *
     * @param  list<int>  $vmIds
     * @param  list<int>  $planIds
     * @param  list<int>  $userIds
     * @param  list<int>  $hostIds
     */
    function vpsPlanConcurrencyCleanup(
        array $vmIds = [],
        array $planIds = [],
        array $userIds = [],
        array $hostIds = [],
        bool $clearHighQueue = false,
    ): void {
        if ($vmIds !== []) {
            DB::table('vps_plan_changes')->whereIn('virtual_machine_id', $vmIds)->delete();
            DB::table('virtual_machines')->whereIn('id', $vmIds)->delete();
        }

        if ($planIds !== []) {
            DB::table('vps_plans')->whereIn('id', $planIds)->update(['current_version_id' => null]);
            DB::table('vps_plan_versions')->whereIn('vps_plan_id', $planIds)->delete();
            DB::table('vps_plans')->whereIn('id', $planIds)->delete();
        }

        if ($userIds !== []) {
            DB::table('user_quotas')->whereIn('user_id', $userIds)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
        }

        if ($hostIds !== []) {
            DB::table('hosts')->whereIn('id', $hostIds)->delete();
        }

        if ($clearHighQueue) {
            Artisan::call('queue:clear', ['connection' => 'redis', '--queue' => 'high']);
        }
    }
}

test('concurrent VpsPlanVersionService::publishDraft calls serialize via VpsPlan lockForUpdate without duplicate versions or gaps', function (): void {
    $suffix = uniqid();
    $admin = User::factory()->create();

    $host = Host::create([
        'name' => 'plan-ver-host-'.$suffix,
        'endpoint_url' => 'https://10.50.1.1:8443',
        'driver' => 'fake',
        'status' => HostOk::$name,
        'is_active' => true,
        'total_cpu_cores' => 16,
        'total_ram_mb' => 32768,
        'total_storage_gb' => 500,
        'capacity_source' => 'discovered',
        'capacity_synced_at' => now(),
    ]);

    $plan = VpsPlan::create([
        'name' => 'Concurrency Plan '.$suffix,
        'slug' => 'concurrency-plan-'.$suffix,
        'status' => PlanDraft::class,
        'sort_order' => 0,
        'created_by' => $admin->id,
    ]);

    $versionService = app(VpsPlanVersionService::class);

    $workerScript = <<<'PHP'
$plan = App\Models\VpsPlan::query()->findOrFail((int) $payload['plan_id']);
$actor = App\Models\User::query()->findOrFail((int) $payload['actor_id']);
$service = app(App\Services\Vps\VpsPlanVersionService::class);

try {
    $published = $service->publishDraft($plan, $actor);
    echo json_encode([
        'status' => 'published',
        'version_id' => $published->id,
        'version' => $published->version,
    ], JSON_THROW_ON_ERROR);
} catch (Illuminate\Validation\ValidationException $e) {
    echo json_encode([
        'status' => 'validation_error',
        'errors' => $e->errors(),
    ], JSON_THROW_ON_ERROR);
}
PHP;

    try {
        for ($round = 1; $round <= 3; $round++) {
            $versionService->upsertDraft($plan, [
                'cpu_cores' => $round,
                'ram_mb' => 1024 * $round,
                'disk_gb' => 10 * $round,
                'instance_type' => 'container',
                'change_summary' => 'Round '.$round,
            ], $admin);

            $results = vpsPlanConcurrencyRunWorkers(
                [
                    ['plan_id' => $plan->id, 'actor_id' => $admin->id],
                    ['plan_id' => $plan->id, 'actor_id' => $admin->id],
                ],
                $workerScript,
            );

            $published = [];
            $noPublishable = [];

            foreach ($results as $res) {
                expect($res['exit'])->toBe(0, $res['stderr']);
                expect($res['stderr'])->toBe('');
                expect($res['json'])->toBeArray();

                if ($res['json']['status'] === 'published') {
                    $published[] = $res['json'];
                } else {
                    expect($res['json']['status'])->toBe('validation_error');
                    expect($res['json']['errors']['version'][0] ?? null)->toBe('NO_PUBLISHABLE_VERSION');
                    $noPublishable[] = $res['json'];
                }
            }

            expect($published)->toHaveCount(1);
            expect($noPublishable)->toHaveCount(1);
            expect($published[0]['version'])->toBe($round);
        }

        $versions = VpsPlanVersion::query()
            ->where('vps_plan_id', $plan->id)
            ->orderBy('version')
            ->get();

        expect($versions)->toHaveCount(3);
        expect($versions->pluck('version')->all())->toBe([1, 2, 3]);
        expect($versions[0]->status->equals(VersionSuperseded::class))->toBeTrue();
        expect($versions[1]->status->equals(VersionSuperseded::class))->toBeTrue();
        expect($versions[2]->status->equals(VersionPublished::class))->toBeTrue();
        expect($plan->fresh()?->current_version_id)->toBe($versions[2]->id);
    } finally {
        vpsPlanConcurrencyCleanup(
            planIds: [$plan->id],
            userIds: [$admin->id],
            hostIds: [$host->id],
        );
    }
})->group('integration');

test('two concurrent plan-change requests for the same user do not exceed UserQuota', function (): void {
    config([
        'provisioning.host_cpu_reserve_cores' => 0,
        'provisioning.host_ram_reserve_mb' => 0,
        'provisioning.host_disk_reserve_gb' => 0,
    ]);

    $suffix = uniqid();
    $user = User::factory()->create();
    UserQuota::create([
        'user_id' => $user->id,
        'max_vms' => 5,
        'max_cpu_cores' => 4,
        'max_ram_mb' => 8192,
        'max_storage_gb' => 200,
        'max_ips' => 5,
    ]);

    $host = Host::create([
        'name' => 'quota-conc-host-'.$suffix,
        'endpoint_url' => 'https://10.50.2.1:8443',
        'driver' => 'fake',
        'status' => HostOk::$name,
        'is_active' => true,
        'total_cpu_cores' => 16,
        'total_ram_mb' => 32768,
        'total_storage_gb' => 500,
        'capacity_source' => 'discovered',
        'capacity_synced_at' => now(),
    ]);

    $plan = VpsPlan::create([
        'name' => 'Quota Plan '.$suffix,
        'slug' => 'quota-plan-'.$suffix,
        'status' => PlanPublished::class,
        'sort_order' => 0,
        'created_by' => $user->id,
    ]);

    $v1 = VpsPlanVersion::create([
        'vps_plan_id' => $plan->id,
        'version' => 1,
        'status' => VersionSuperseded::class,
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'instance_type' => 'container',
        'created_by' => $user->id,
        'published_by' => $user->id,
        'created_at' => now(),
        'published_at' => now(),
    ]);

    $v2 = VpsPlanVersion::create([
        'vps_plan_id' => $plan->id,
        'version' => 2,
        'status' => VersionPublished::class,
        'cpu_cores' => 3,
        'ram_mb' => 2048,
        'disk_gb' => 10,
        'instance_type' => 'container',
        'created_by' => $user->id,
        'published_by' => $user->id,
        'created_at' => now(),
        'published_at' => now(),
    ]);

    $plan->update(['current_version_id' => $v2->id]);

    $vmA = VirtualMachine::create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'vps_plan_version_id' => $v1->id,
        'name' => 'quota-vm-a-'.$suffix,
        'incus_project' => 'default',
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'os_image' => 'ubuntu:24.04',
    ]);

    $vmB = VirtualMachine::create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'vps_plan_version_id' => $v1->id,
        'name' => 'quota-vm-b-'.$suffix,
        'incus_project' => 'default',
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'os_image' => 'ubuntu:24.04',
    ]);

    $workerScript = <<<'PHP'
$vm = App\Models\VirtualMachine::query()->findOrFail((int) $payload['vm_id']);
$version = App\Models\VpsPlanVersion::query()->findOrFail((int) $payload['version_id']);
$actor = App\Models\User::query()->findOrFail((int) $payload['actor_id']);
$service = app(App\Services\Vps\VpsPlanChangeService::class);

try {
    $change = $service->request($vm, $version, $actor);
    echo json_encode([
        'status' => 'created',
        'change_id' => $change->id,
    ], JSON_THROW_ON_ERROR);
} catch (Illuminate\Validation\ValidationException $e) {
    $responseData = $e->response?->getData(true);
    echo json_encode([
        'status' => 'validation_error',
        'code' => is_array($responseData) ? ($responseData['code'] ?? null) : null,
        'errors' => $e->errors(),
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
PHP;

    try {
        $results = vpsPlanConcurrencyRunWorkers(
            [
                ['vm_id' => $vmA->id, 'version_id' => $v2->id, 'actor_id' => $user->id],
                ['vm_id' => $vmB->id, 'version_id' => $v2->id, 'actor_id' => $user->id],
            ],
            $workerScript,
            [
                'PROVISION_HOST_CPU_RESERVE_CORES' => '0',
                'PROVISION_HOST_RAM_RESERVE_MB' => '0',
                'PROVISION_HOST_DISK_RESERVE_GB' => '0',
            ],
        );

        $createdCount = 0;
        $rejectedCount = 0;

        foreach ($results as $res) {
            expect($res['exit'])->toBe(0, $res['stderr']);
            expect($res['stderr'])->toBe('');
            expect($res['json'])->toBeArray();

            if ($res['json']['status'] === 'created') {
                $createdCount++;
            } else {
                $rejectedCount++;
                expect($res['json']['status'])->toBe('validation_error');
                expect($res['json']['code'])->toBe('QUOTA_EXCEEDED');
            }
        }

        expect($createdCount)->toBe(1);
        expect($rejectedCount)->toBe(1);

        $activeChanges = VpsPlanChange::query()
            ->whereIn('virtual_machine_id', [$vmA->id, $vmB->id])
            ->get();

        expect($activeChanges)->toHaveCount(1);
        expect($activeChanges[0]->status->equals(Pending::class))->toBeTrue();

        $reservations = app(ResourceReservationService::class)->getUserReservations($user->id);
        expect($reservations)->toBe(['cpu' => 2, 'ram' => 1024, 'disk' => 0]);

        $totalCommittedCpu = (int) VirtualMachine::query()->where('user_id', $user->id)->sum('cpu_cores') + $reservations['cpu'];
        expect($totalCommittedCpu)->toBe(4);
    } finally {
        vpsPlanConcurrencyCleanup(
            vmIds: [$vmA->id, $vmB->id],
            planIds: [$plan->id],
            userIds: [$user->id],
            hostIds: [$host->id],
            clearHighQueue: true,
        );
    }
})->group('integration');

test('two concurrent plan-change requests on the same host do not exceed HostCapacityGuard', function (): void {
    config([
        'provisioning.host_cpu_reserve_cores' => 0,
        'provisioning.host_ram_reserve_mb' => 0,
        'provisioning.host_disk_reserve_gb' => 0,
    ]);

    $suffix = uniqid();
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    foreach ([$userA, $userB] as $tenant) {
        UserQuota::create([
            'user_id' => $tenant->id,
            'max_vms' => 5,
            'max_cpu_cores' => 16,
            'max_ram_mb' => 16384,
            'max_storage_gb' => 200,
            'max_ips' => 5,
        ]);
    }

    // Total host CPU = 4; two VMs already use 1 CPU each (2 used).
    // Only ONE +2 CPU upgrade (to 3 CPU) can fit without exceeding 4 CPU.
    $host = Host::create([
        'name' => 'host-cap-conc-'.$suffix,
        'endpoint_url' => 'https://10.50.3.1:8443',
        'driver' => 'fake',
        'status' => HostOk::$name,
        'is_active' => true,
        'total_cpu_cores' => 4,
        'total_ram_mb' => 8192,
        'total_storage_gb' => 200,
        'capacity_source' => 'discovered',
        'capacity_synced_at' => now(),
    ]);

    $plan = VpsPlan::create([
        'name' => 'Host Capacity Plan '.$suffix,
        'slug' => 'host-cap-plan-'.$suffix,
        'status' => PlanPublished::class,
        'sort_order' => 0,
        'created_by' => $userA->id,
    ]);

    $v1 = VpsPlanVersion::create([
        'vps_plan_id' => $plan->id,
        'version' => 1,
        'status' => VersionSuperseded::class,
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'instance_type' => 'container',
        'created_by' => $userA->id,
        'published_by' => $userA->id,
        'created_at' => now(),
        'published_at' => now(),
    ]);

    $v2 = VpsPlanVersion::create([
        'vps_plan_id' => $plan->id,
        'version' => 2,
        'status' => VersionPublished::class,
        'cpu_cores' => 3,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'container',
        'created_by' => $userA->id,
        'published_by' => $userA->id,
        'created_at' => now(),
        'published_at' => now(),
    ]);

    $plan->update(['current_version_id' => $v2->id]);

    $vmA = VirtualMachine::create([
        'user_id' => $userA->id,
        'host_id' => $host->id,
        'vps_plan_version_id' => $v1->id,
        'name' => 'host-cap-vm-a-'.$suffix,
        'incus_project' => 'default',
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'os_image' => 'ubuntu:24.04',
    ]);

    $vmB = VirtualMachine::create([
        'user_id' => $userB->id,
        'host_id' => $host->id,
        'vps_plan_version_id' => $v1->id,
        'name' => 'host-cap-vm-b-'.$suffix,
        'incus_project' => 'default',
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'os_image' => 'ubuntu:24.04',
    ]);

    $workerScript = <<<'PHP'
$vm = App\Models\VirtualMachine::query()->findOrFail((int) $payload['vm_id']);
$version = App\Models\VpsPlanVersion::query()->findOrFail((int) $payload['version_id']);
$actor = App\Models\User::query()->findOrFail((int) $payload['actor_id']);
$service = app(App\Services\Vps\VpsPlanChangeService::class);

try {
    $change = $service->request($vm, $version, $actor);
    echo json_encode([
        'status' => 'created',
        'change_id' => $change->id,
    ], JSON_THROW_ON_ERROR);
} catch (Illuminate\Validation\ValidationException $e) {
    $responseData = $e->response?->getData(true);
    echo json_encode([
        'status' => 'validation_error',
        'code' => is_array($responseData) ? ($responseData['code'] ?? null) : null,
        'errors' => $e->errors(),
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
PHP;

    try {
        $results = vpsPlanConcurrencyRunWorkers(
            [
                ['vm_id' => $vmA->id, 'version_id' => $v2->id, 'actor_id' => $userA->id],
                ['vm_id' => $vmB->id, 'version_id' => $v2->id, 'actor_id' => $userB->id],
            ],
            $workerScript,
            [
                'PROVISION_HOST_CPU_RESERVE_CORES' => '0',
                'PROVISION_HOST_RAM_RESERVE_MB' => '0',
                'PROVISION_HOST_DISK_RESERVE_GB' => '0',
            ],
        );

        $createdCount = 0;
        $rejectedCount = 0;

        foreach ($results as $res) {
            expect($res['exit'])->toBe(0, $res['stderr']);
            expect($res['stderr'])->toBe('');
            expect($res['json'])->toBeArray();

            if ($res['json']['status'] === 'created') {
                $createdCount++;
            } else {
                $rejectedCount++;
                expect($res['json']['status'])->toBe('validation_error');
                expect($res['json']['code'])->toBe('HOST_CAPACITY_EXCEEDED');
            }
        }

        expect($createdCount)->toBe(1);
        expect($rejectedCount)->toBe(1);

        $activeChanges = VpsPlanChange::query()
            ->whereIn('virtual_machine_id', [$vmA->id, $vmB->id])
            ->get();

        expect($activeChanges)->toHaveCount(1);
        expect($activeChanges[0]->status->equals(Pending::class))->toBeTrue();

        $reservations = app(ResourceReservationService::class)->getHostReservations($host->id);
        expect($reservations)->toBe(['cpu' => 2, 'ram' => 1024, 'disk' => 10]);
    } finally {
        vpsPlanConcurrencyCleanup(
            vmIds: [$vmA->id, $vmB->id],
            planIds: [$plan->id],
            userIds: [$userA->id, $userB->id],
            hostIds: [$host->id],
            clearHighQueue: true,
        );
    }
})->group('integration');

test('MySQL active_vm_id unique constraint rejects a second active plan change while allowing multiple terminal rows', function (): void {
    expect(DB::connection()->getDriverName())->toBe('mysql');

    $suffix = uniqid();
    $user = User::factory()->create();

    $host = Host::create([
        'name' => 'unique-vm-host-'.$suffix,
        'endpoint_url' => 'https://10.50.4.1:8443',
        'driver' => 'fake',
        'status' => HostOk::$name,
        'is_active' => true,
        'total_cpu_cores' => 8,
        'total_ram_mb' => 16384,
        'total_storage_gb' => 200,
        'capacity_source' => 'discovered',
        'capacity_synced_at' => now(),
    ]);

    $plan = VpsPlan::create([
        'name' => 'Unique Active VM Plan '.$suffix,
        'slug' => 'unique-active-vm-plan-'.$suffix,
        'status' => PlanPublished::class,
        'sort_order' => 0,
        'created_by' => $user->id,
    ]);

    $version = VpsPlanVersion::create([
        'vps_plan_id' => $plan->id,
        'version' => 1,
        'status' => VersionPublished::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'container',
        'created_by' => $user->id,
        'published_by' => $user->id,
        'created_at' => now(),
        'published_at' => now(),
    ]);

    $plan->update(['current_version_id' => $version->id]);

    $vm = VirtualMachine::create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'name' => 'unique-active-vm-'.$suffix,
        'incus_project' => 'default',
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'os_image' => 'ubuntu:24.04',
    ]);

    $insertChangeRow = static function (string $status) use ($vm, $user, $version): int {
        return (int) DB::table('vps_plan_changes')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()),
            'virtual_machine_id' => $vm->id,
            'user_id' => $user->id,
            'initiated_by' => $user->id,
            'from_version_id' => null,
            'to_version_id' => $version->id,
            'old_resources' => json_encode(['cpu_cores' => 1, 'ram_mb' => 1024, 'disk_gb' => 10], JSON_THROW_ON_ERROR),
            'new_resources' => json_encode(['cpu_cores' => 2, 'ram_mb' => 2048, 'disk_gb' => 20], JSON_THROW_ON_ERROR),
            'status' => $status,
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    try {
        // Multiple terminal rows for the same VM are allowed (virtual active_vm_id evaluates to NULL).
        $terminalStatuses = [
            Completed::$name,
            Failed::$name,
            Cancelled::$name,
            Completed::$name,
        ];

        $terminalIds = [];
        foreach ($terminalStatuses as $status) {
            $terminalIds[] = $insertChangeRow($status);
        }

        expect($terminalIds)->toHaveCount(4);
        $nullActiveCount = DB::table('vps_plan_changes')
            ->whereIn('id', $terminalIds)
            ->whereNull('active_vm_id')
            ->count();
        expect($nullActiveCount)->toBe(4);

        // First active row succeeds and populates virtual column active_vm_id.
        $activeId = $insertChangeRow(Pending::$name);
        $activeRow = DB::table('vps_plan_changes')->where('id', $activeId)->first(['active_vm_id']);
        expect($activeRow)->not->toBeNull();
        expect((int) $activeRow->active_vm_id)->toBe($vm->id);

        // Any second active row for the same VM fails at the MySQL unique constraint level.
        $activeStatuses = [
            Pending::$name,
            AwaitingAuthorization::$name,
            Applying::$name,
            ReconciliationRequired::$name,
        ];

        foreach ($activeStatuses as $status) {
            $rejected = false;
            try {
                $insertChangeRow($status);
            } catch (QueryException $e) {
                $rejected = true;
                expect($e->getMessage())->toContain('vps_plan_changes_active_vm_id_unique');
            }

            expect($rejected)->toBeTrue("Expected duplicate active row with status '{$status}' to be rejected by MySQL.");
        }

        // Transitioning the active row to a terminal state clears active_vm_id and allows a new active row.
        DB::table('vps_plan_changes')->where('id', $activeId)->update([
            'status' => Completed::$name,
            'completed_at' => now(),
        ]);

        $clearedRow = DB::table('vps_plan_changes')->where('id', $activeId)->first(['active_vm_id']);
        expect($clearedRow)->not->toBeNull();
        expect($clearedRow->active_vm_id)->toBeNull();

        $nextActiveId = $insertChangeRow(Pending::$name);
        expect($nextActiveId)->toBeGreaterThan($activeId);
    } finally {
        vpsPlanConcurrencyCleanup(
            vmIds: [$vm->id],
            planIds: [$plan->id],
            userIds: [$user->id],
            hostIds: [$host->id],
        );
    }
})->group('integration');

test('compare-and-set race between cancel() and startApplying() lets exactly one succeed and the other fail cleanly', function (): void {
    $suffix = uniqid();
    $user = User::factory()->create();

    $host = Host::create([
        'name' => 'cas-race-host-'.$suffix,
        'endpoint_url' => 'https://10.50.5.1:8443',
        'driver' => 'fake',
        'status' => HostOk::$name,
        'is_active' => true,
        'total_cpu_cores' => 8,
        'total_ram_mb' => 16384,
        'total_storage_gb' => 200,
        'capacity_source' => 'discovered',
        'capacity_synced_at' => now(),
    ]);

    $plan = VpsPlan::create([
        'name' => 'CAS Race Plan '.$suffix,
        'slug' => 'cas-race-plan-'.$suffix,
        'status' => PlanPublished::class,
        'sort_order' => 0,
        'created_by' => $user->id,
    ]);

    $v1 = VpsPlanVersion::create([
        'vps_plan_id' => $plan->id,
        'version' => 1,
        'status' => VersionSuperseded::class,
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'instance_type' => 'container',
        'created_by' => $user->id,
        'published_by' => $user->id,
        'created_at' => now(),
        'published_at' => now(),
    ]);

    $v2 = VpsPlanVersion::create([
        'vps_plan_id' => $plan->id,
        'version' => 2,
        'status' => VersionPublished::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'container',
        'created_by' => $user->id,
        'published_by' => $user->id,
        'created_at' => now(),
        'published_at' => now(),
    ]);

    $plan->update(['current_version_id' => $v2->id]);

    $vm = VirtualMachine::create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'vps_plan_version_id' => $v1->id,
        'name' => 'cas-race-vm-'.$suffix,
        'incus_project' => 'default',
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'os_image' => 'ubuntu:24.04',
    ]);

    $workerScript = <<<'PHP'
$change = App\Models\VpsPlanChange::query()->findOrFail((int) $payload['change_id']);
$service = app(App\Services\Vps\VpsPlanChangeService::class);
$mode = (string) $payload['mode'];

if ($mode === 'cancel') {
    $actor = App\Models\User::query()->findOrFail((int) $payload['actor_id']);
    try {
        $updated = $service->cancel($change, $actor);
        echo json_encode([
            'role' => 'cancel',
            'outcome' => 'succeeded',
            'status' => $updated->status->getValue(),
        ], JSON_THROW_ON_ERROR);
    } catch (Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
        echo json_encode([
            'role' => 'cancel',
            'outcome' => 'conflict',
            'message' => $e->getMessage(),
        ], JSON_THROW_ON_ERROR);
    }

    exit(0);
}

$started = $service->startApplying($change);
echo json_encode([
    'role' => 'start_applying',
    'outcome' => $started !== null ? 'succeeded' : 'noop',
    'status' => $started?->status->getValue(),
], JSON_THROW_ON_ERROR);
PHP;

    try {
        for ($round = 1; $round <= 3; $round++) {
            $change = VpsPlanChange::query()->create([
                'public_id' => strtolower((string) Str::ulid()),
                'virtual_machine_id' => $vm->id,
                'user_id' => $user->id,
                'initiated_by' => $user->id,
                'from_version_id' => $v1->id,
                'to_version_id' => $v2->id,
                'old_resources' => [
                    'cpu_cores' => 1,
                    'ram_mb' => 1024,
                    'disk_gb' => 10,
                    'net_download_mbps' => null,
                    'net_upload_mbps' => null,
                ],
                'new_resources' => [
                    'cpu_cores' => 2,
                    'ram_mb' => 2048,
                    'disk_gb' => 20,
                    'net_download_mbps' => null,
                    'net_upload_mbps' => null,
                ],
                'status' => Pending::class,
                'requested_at' => now(),
            ]);

            $payloads = $round % 2 === 1
                ? [
                    ['mode' => 'cancel', 'change_id' => $change->id, 'actor_id' => $user->id],
                    ['mode' => 'start_applying', 'change_id' => $change->id],
                ]
                : [
                    ['mode' => 'start_applying', 'change_id' => $change->id],
                    ['mode' => 'cancel', 'change_id' => $change->id, 'actor_id' => $user->id],
                ];

            $results = vpsPlanConcurrencyRunWorkers($payloads, $workerScript);

            $byRole = [];
            foreach ($results as $res) {
                expect($res['exit'])->toBe(0, $res['stderr']);
                expect($res['stderr'])->toBe('');
                expect($res['json'])->toBeArray();
                $byRole[(string) $res['json']['role']] = $res['json'];
            }

            expect($byRole)->toHaveKeys(['cancel', 'start_applying']);

            $cancelResult = $byRole['cancel'];
            $startResult = $byRole['start_applying'];
            $fresh = $change->fresh();
            expect($fresh)->not->toBeNull();

            $cancelWon = $cancelResult['outcome'] === 'succeeded';
            $startWon = $startResult['outcome'] === 'succeeded';

            // Mutually exclusive CAS: exactly one must succeed and the other must fail cleanly.
            expect($cancelWon !== $startWon)->toBeTrue();

            if ($cancelWon) {
                expect($cancelResult['status'])->toBe(Cancelled::$name);
                expect($startResult['outcome'])->toBe('noop');
                expect($fresh->status->equals(Cancelled::class))->toBeTrue();
                expect($fresh->started_at)->toBeNull();
            } else {
                expect($startResult['status'])->toBe(Applying::$name);
                expect($cancelResult['outcome'])->toBe('conflict');
                expect($fresh->status->equals(Applying::class))->toBeTrue();
                expect($fresh->started_at)->not->toBeNull();
                expect($fresh->apply_deadline)->not->toBeNull();

                // Close out active Applying row so the next round can create a new Pending row.
                DB::table('vps_plan_changes')->where('id', $change->id)->update([
                    'status' => Completed::$name,
                    'completed_at' => now(),
                ]);
            }
        }
    } finally {
        vpsPlanConcurrencyCleanup(
            vmIds: [$vm->id],
            planIds: [$plan->id],
            userIds: [$user->id],
            hostIds: [$host->id],
        );
    }
})->group('integration');
