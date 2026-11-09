<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Integration');

/**
 * Skip the current test if MySQL or Redis infrastructure is not available.
 */
function skipUnlessRealInfrastructure(): void
{
    if (config('database.default') !== 'mysql') {
        test()->markTestSkipped(sprintf(
            'Integration test skipped: DB_CONNECTION is not mysql (currently "%s").',
            (string) config('database.default')
        ));
    }

    try {
        DB::connection('mysql')->getPdo();
    } catch (Throwable $e) {
        test()->markTestSkipped('Integration test skipped: Cannot connect to MySQL: '.$e->getMessage());
    }

    try {
        Redis::connection()->ping();
    } catch (Throwable $e) {
        test()->markTestSkipped('Integration test skipped: Cannot connect to Redis: '.$e->getMessage());
    }
}

/**
 * Run php artisan queue:work redis --stop-when-empty in a subprocess until empty or timeout.
 */
function runWorkerUntilEmpty(int $maxSeconds = 10): int
{
    $artisan = base_path('artisan');
    $php = PHP_BINARY ?: 'php';

    $cmd = [
        $php,
        $artisan,
        'queue:work',
        'redis',
        '--stop-when-empty',
        '--timeout='.$maxSeconds,
    ];

    $cleanEnv = [];
    foreach (array_merge(getenv(), $_ENV, $_SERVER) as $k => $v) {
        if (is_scalar($v)) {
            $cleanEnv[(string) $k] = (string) $v;
        }
    }

    $env = array_merge($cleanEnv, [
        'APP_ENV' => 'testing',
        'QUEUE_CONNECTION' => 'redis',
        'CACHE_STORE' => 'redis',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => (string) config('database.connections.mysql.host', '127.0.0.1'),
        'DB_PORT' => (string) config('database.connections.mysql.port', 3306),
        'DB_DATABASE' => (string) config('database.connections.mysql.database', 'ognivo_test'),
        'DB_USERNAME' => (string) config('database.connections.mysql.username', 'root'),
        'DB_PASSWORD' => (string) (config('database.connections.mysql.password') ?? ''),
        'REDIS_HOST' => (string) config('database.redis.default.host', '127.0.0.1'),
        'REDIS_PORT' => (string) config('database.redis.default.port', 6379),
        'REDIS_CLIENT' => (string) env('REDIS_CLIENT', 'predis'),
    ]);

    $process = new Process($cmd, base_path(), $env);
    $process->setTimeout((float) $maxSeconds);
    $process->run();

    return $process->getExitCode() ?? 1;
}
