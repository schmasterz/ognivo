<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Metrics\MetricsPartitionManager;
use Illuminate\Support\Facades\DB;

uses()->group('integration');

beforeEach(function (): void {
    skipUnlessRealInfrastructure();
});

test('MySQL 8.4 RANGE partitions are active, reorganize future partitions, and prune expired partitions via DROP PARTITION (AC-5)', function (): void {
    expect(DB::connection()->getDriverName())->toBe('mysql');

    $schema = DB::connection()->getDatabaseName();
    $tables = ['vm_metric_samples', 'vm_metric_rollups_5m', 'vm_metric_rollups_1h'];

    $fetchPartitions = static function (string $table) use ($schema): array {
        $rows = DB::select(
            'SELECT PARTITION_NAME, PARTITION_METHOD, PARTITION_EXPRESSION, PARTITION_DESCRIPTION
             FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL
             ORDER BY PARTITION_ORDINAL_POSITION',
            [$schema, $table],
        );

        return array_map(static function (object $row): array {
            $attrs = array_change_key_case(get_object_vars($row), CASE_UPPER);

            return [
                'name' => (string) ($attrs['PARTITION_NAME'] ?? ''),
                'method' => (string) ($attrs['PARTITION_METHOD'] ?? ''),
                'expression' => (string) ($attrs['PARTITION_EXPRESSION'] ?? ''),
                'description' => (string) ($attrs['PARTITION_DESCRIPTION'] ?? ''),
            ];
        }, $rows);
    };

    $beforeNames = [];
    foreach ($tables as $table) {
        $partitions = $fetchPartitions($table);
        expect($partitions)->not->toBeEmpty();
        expect($partitions[0]['method'])->toBe('RANGE');
        expect(strtolower($partitions[0]['expression']))->toContain('bucket_ts');

        $names = array_column($partitions, 'name');
        expect($names)->toContain('p_future');
        $beforeNames[$table] = $names;
    }

    $now = time();
    /** @var MetricsPartitionManager $manager */
    $manager = app(MetricsPartitionManager::class);
    $manager->ensureFuturePartitions($now);

    $expectedPrefixes = [
        'vm_metric_samples' => 'p_raw_',
        'vm_metric_rollups_5m' => 'p_5m_',
        'vm_metric_rollups_1h' => 'p_1h_',
    ];

    $afterFutureNames = [];
    foreach ($tables as $table) {
        $partitions = $fetchPartitions($table);
        $names = array_column($partitions, 'name');
        expect($names)->toContain('p_future');
        expect(count($names))->toBeGreaterThanOrEqual(count($beforeNames[$table]));

        $prefix = $expectedPrefixes[$table];
        $hasPrefixedPartition = false;
        foreach ($names as $name) {
            if (str_starts_with($name, $prefix)) {
                $hasPrefixedPartition = true;
                break;
            }
        }
        expect($hasPrefixedPartition)->toBeTrue();
        $afterFutureNames[$table] = $names;
    }

    $pruneStats = $manager->pruneExpiredData($now + (14 * 86400));
    expect($pruneStats['dropped_partitions'])->toBeGreaterThan(0);

    $rawAfterPrune = array_column($fetchPartitions('vm_metric_samples'), 'name');
    expect(count($rawAfterPrune))->toBeLessThan(count($afterFutureNames['vm_metric_samples']));
    expect($rawAfterPrune)->toContain('p_future');

    $rollup5mAfterPrune = array_column($fetchPartitions('vm_metric_rollups_5m'), 'name');
    expect(count($rollup5mAfterPrune))->toBeLessThan(count($afterFutureNames['vm_metric_rollups_5m']));
    expect($rollup5mAfterPrune)->toContain('p_future');
});

test('metrics:benchmark-nfr passes all NFR-1, NFR-3, NFR-4, NFR-5, and NFR-9 targets on MySQL 8.4 + Redis 7 (AC-11, AC-19)', function (): void {
    $this->artisan('metrics:benchmark-nfr', [
        '--nodes' => 20,
        '--instances' => 500,
        '--queries' => 15,
        '--cleanup' => true,
    ])->assertSuccessful();
});
