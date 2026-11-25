<?php

declare(strict_types=1);

use App\Services\Metrics\VmMetricsConfigService;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config([
        'metrics.enabled' => true,
        'metrics.interval_seconds' => 60,
        'metrics.request_timeout_seconds' => 10,
        'metrics.retention.raw_hours' => 12,
        'metrics.retention.rollup_5m_days' => 8,
        'metrics.retention.rollup_1h_days' => 35,
        'metrics.audit_window_seconds' => 900,
    ]);
});

it('resolves default query plans for all five ranges at 60s interval and max_points 500', function (
    string $range,
    string $expectedTier,
    string $expectedTable,
    int $expectedDuration,
    int $expectedTierRes,
    int $expectedBucketWidth,
    int $expectedBucketCount,
    int $expectedRefreshInterval,
): void {
    $service = new VmMetricsConfigService;

    $plan = $service->resolveQueryPlan($range);

    expect($plan)->toBe([
        'range' => $range,
        'tier' => $expectedTier,
        'table' => $expectedTable,
        'duration_seconds' => $expectedDuration,
        'tier_resolution_seconds' => $expectedTierRes,
        'bucket_width_seconds' => $expectedBucketWidth,
        'bucket_count' => $expectedBucketCount,
        'refresh_interval_seconds' => $expectedRefreshInterval,
    ])
        ->and($plan['bucket_count'])->toBeLessThanOrEqual(VmMetricsConfigService::MAX_POINTS_CAP);
})->with([
    '1h' => ['1h', 'raw', 'vm_metric_samples', 3600, 60, 60, 60, 60],
    '6h' => ['6h', 'raw', 'vm_metric_samples', 21600, 60, 60, 360, 60],
    '24h' => ['24h', 'rollup_5m', 'vm_metric_rollups_5m', 86400, 300, 300, 288, 300],
    '7d' => ['7d', 'rollup_5m', 'vm_metric_rollups_5m', 604800, 300, 1500, 403, 3600],
    '30d' => ['30d', 'rollup_1h', 'vm_metric_rollups_1h', 2592000, 3600, 7200, 360, 3600],
]);

it('widens buckets when max_points is reduced', function (): void {
    $service = new VmMetricsConfigService;

    $plan = $service->resolveQueryPlan('24h', 100);

    expect($plan['tier'])->toBe('rollup_5m')
        ->and($plan['tier_resolution_seconds'])->toBe(300)
        ->and($plan['bucket_width_seconds'])->toBe(900)
        ->and($plan['bucket_count'])->toBe(96);
});

it('widens buckets when collection interval produces more than max_points native points', function (): void {
    config(['metrics.interval_seconds' => 30]);
    $service = new VmMetricsConfigService;

    $plan = $service->resolveQueryPlan('6h');

    expect($plan['tier'])->toBe('raw')
        ->and($plan['tier_resolution_seconds'])->toBe(30)
        ->and($plan['bucket_width_seconds'])->toBe(60)
        ->and($plan['bucket_count'])->toBe(360)
        ->and($plan['refresh_interval_seconds'])->toBe(30);
});

it('returns all five ranges under default retentions', function (): void {
    $service = new VmMetricsConfigService;

    expect($service->availableRanges())->toBe(['1h', '6h', '24h', '7d', '30d'])
        ->and($service->isRangeAvailable('30d'))->toBeTrue();
});

it('excludes 30d when rollup_1h retention is below 35 days', function (): void {
    config(['metrics.retention.rollup_1h_days' => 20]);
    $service = new VmMetricsConfigService;

    expect($service->availableRanges())->toBe(['1h', '6h', '24h', '7d'])
        ->and($service->isRangeAvailable('30d'))->toBeFalse()
        ->and($service->isRangeAvailable('7d'))->toBeTrue();
});

it('excludes 7d when rollup_5m retention is below 8 days', function (): void {
    config(['metrics.retention.rollup_5m_days' => 5]);
    $service = new VmMetricsConfigService;

    expect($service->availableRanges())->toBe(['1h', '6h', '24h', '30d'])
        ->and($service->isRangeAvailable('7d'))->toBeFalse()
        ->and($service->isRangeAvailable('24h'))->toBeTrue();
});

it('excludes 6h when raw retention is below 6 hours', function (): void {
    config(['metrics.retention.raw_hours' => 4]);
    $service = new VmMetricsConfigService;

    expect($service->availableRanges())->toBe(['1h', '24h', '7d', '30d'])
        ->and($service->isRangeAvailable('6h'))->toBeFalse()
        ->and($service->isRangeAvailable('1h'))->toBeTrue();
});

it('clamps interval, request timeout, and audit window bounds', function (): void {
    config([
        'metrics.enabled' => false,
        'metrics.interval_seconds' => 10,
        'metrics.request_timeout_seconds' => 30,
        'metrics.audit_window_seconds' => 15,
    ]);
    $service = new VmMetricsConfigService;

    expect($service->isEnabled())->toBeFalse()
        ->and($service->intervalSeconds())->toBe(30)
        ->and($service->requestTimeoutSeconds())->toBe(10)
        ->and($service->auditWindowSeconds())->toBe(60);

    config(['metrics.interval_seconds' => 600]);
    expect($service->intervalSeconds())->toBe(300);
});

it('rejects unsupported range or invalid max_points', function (): void {
    $service = new VmMetricsConfigService;

    expect(fn () => $service->resolveQueryPlan('12h'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->resolveQueryPlan('1h', 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->resolveQueryPlan('1h', 501))->toThrow(InvalidArgumentException::class);
});
