<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Host;
use App\Models\Image;
use App\Models\IpPool;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;

test('two concurrent provisioning requests from different users where only one fits results in exactly one success', function (): void {
    skipUnlessRealInfrastructure();

    config([
        'provisioning.host_cpu_reserve_cores' => 0,
        'provisioning.host_ram_reserve_mb' => 0,
        'provisioning.host_disk_reserve_gb' => 0,
    ]);

    Image::firstOrCreate(
        ['name' => 'ubuntu:24.04'],
        [
            'source' => 'images:ubuntu/24.04',
            'enabled' => true,
        ]
    );

    // Host that can only fit ONE 4-core / 4096MB VM
    $host = Host::create([
        'name' => 'concurrency-host-'.uniqid(),
        'endpoint_url' => 'https://10.99.'.rand(1, 200).'.1:8443',
        'driver' => 'fake',
        'status' => 'ok',
        'is_active' => true,
        'total_cpu_cores' => 4,
        'total_ram_mb' => 4096,
        'total_storage_gb' => 100,
        'capacity_source' => 'discovered',
        'capacity_synced_at' => now(),
    ]);

    IpPool::create([
        'host_id' => $host->id,
        'name' => 'Concurrency Pool '.uniqid(),
        'cidr' => '10.88.'.rand(1, 200).'.0/24',
        'first_usable' => '10.88.1.10',
        'last_usable' => '10.88.1.50',
        'network_mode' => 'routed',
        'parent_interface' => 'eth0',
        'active' => true,
        'is_public' => true,
    ]);

    $userA = User::factory()->create();
    UserQuota::create([
        'user_id' => $userA->id,
        'max_vms' => 5,
        'max_cpu_cores' => 8,
        'max_ram_mb' => 8192,
        'max_storage_gb' => 200,
        'max_ips' => 5,
    ]);

    $userB = User::factory()->create();
    UserQuota::create([
        'user_id' => $userB->id,
        'max_vms' => 5,
        'max_cpu_cores' => 8,
        'max_ram_mb' => 8192,
        'max_storage_gb' => 200,
        'max_ips' => 5,
    ]);

    $artisan = base_path('artisan');
    $php = PHP_BINARY ?: 'php';

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
        'PROVISION_HOST_CPU_RESERVE_CORES' => '0',
        'PROVISION_HOST_RAM_RESERVE_MB' => '0',
        'PROVISION_HOST_DISK_RESERVE_GB' => '0',
    ]);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $cmdA = sprintf('%s %s tests:provision-vm %d %d 4 4096 100', escapeshellarg($php), escapeshellarg($artisan), $userA->id, $host->id);
    $cmdB = sprintf('%s %s tests:provision-vm %d %d 4 4096 100', escapeshellarg($php), escapeshellarg($artisan), $userB->id, $host->id);

    $procA = proc_open($cmdA, $descriptors, $pipesA, base_path(), $env);
    $procB = proc_open($cmdB, $descriptors, $pipesB, base_path(), $env);

    expect(is_resource($procA))->toBeTrue();
    expect(is_resource($procB))->toBeTrue();

    fclose($pipesA[0]);
    fclose($pipesB[0]);

    $outA = (string) stream_get_contents($pipesA[1]);
    $errA = (string) stream_get_contents($pipesA[2]);
    fclose($pipesA[1]);
    fclose($pipesA[2]);
    $exitA = proc_close($procA);

    $outB = (string) stream_get_contents($pipesB[1]);
    $errB = (string) stream_get_contents($pipesB[2]);
    fclose($pipesB[1]);
    fclose($pipesB[2]);
    $exitB = proc_close($procB);

    $results = [
        ['exit' => $exitA, 'out' => $outA, 'err' => $errA],
        ['exit' => $exitB, 'out' => $outB, 'err' => $errB],
    ];

    $successCount = 0;
    $failureCount = 0;

    foreach ($results as $res) {
        if ($res['exit'] === 0 && str_contains($res['out'], 'SUCCESS:')) {
            $successCount++;
        } else {
            $failureCount++;
            $output = $res['err'] ?: $res['out'];
            expect($output)->toContain('capacity');
        }
    }

    // Exactly one must succeed and exactly one must fail
    expect($successCount)->toBe(1);
    expect($failureCount)->toBe(1);

    // Exactly one VM provisioned on the host in DB
    $vmsOnHost = VirtualMachine::where('host_id', $host->id)->get();
    expect($vmsOnHost)->toHaveCount(1);
})->group('integration');
