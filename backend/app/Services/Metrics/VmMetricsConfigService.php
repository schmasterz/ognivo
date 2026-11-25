<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\DTO\Cast;
use App\Models\SystemSetting;
use InvalidArgumentException;
use Throwable;

/**
 * Single source of truth for instance metrics feature flags, collection intervals,
 * tier retentions, available UI ranges, and query tier/bucket planning.
 */
final class VmMetricsConfigService
{
    /**
     * Supported time range identifiers ordered from shortest to longest.
     *
     * @var list<string>
     */
    public const array SUPPORTED_RANGES = ['1h', '6h', '24h', '7d', '30d'];

    /**
     * Supported metric series identifiers exposed by the query API.
     *
     * @var list<string>
     */
    public const array SUPPORTED_METRICS = ['cpu', 'memory', 'disk_space', 'disk_io', 'network'];

    public const int MAX_POINTS_CAP = 500;

    public const int MIN_RAW_RETENTION_HOURS = 6;

    public const int MIN_ROLLUP_5M_RETENTION_DAYS = 8;

    public const int MIN_ROLLUP_1H_RETENTION_DAYS = 35;

    /**
     * Determine whether instance metrics collection and query API are enabled.
     */
    public function isEnabled(): bool
    {
        /** @var mixed $setting */
        $setting = $this->getSystemSettingOrNull('metrics.enabled');
        if ($setting !== null) {
            return $setting === true || $setting === 'true' || $setting === 1 || $setting === '1';
        }

        return Cast::bool(config('metrics.enabled', true), true);
    }

    /**
     * Return the collection interval in seconds, clamped to [30, 300].
     */
    public function intervalSeconds(): int
    {
        $val = $this->getIntSetting('metrics.interval_seconds', 'metrics.interval_seconds', 60);

        return max(30, min(300, $val));
    }

    /**
     * Return the per-node hypervisor scrape HTTP timeout in seconds, clamped to [1, 10].
     */
    public function requestTimeoutSeconds(): int
    {
        $val = Cast::int(config('metrics.request_timeout_seconds', 10), 10);

        return max(1, min(10, $val));
    }

    /**
     * Return raw sample retention in hours (minimum 1).
     */
    public function rawRetentionHours(): int
    {
        $val = $this->getIntSetting('metrics.retention.raw_hours', 'metrics.retention.raw_hours', 12);

        return max(1, $val);
    }

    /**
     * Return 5-minute rollup retention in days (minimum 1).
     */
    public function rollup5mRetentionDays(): int
    {
        $val = $this->getIntSetting('metrics.retention.rollup_5m_days', 'metrics.retention.rollup_5m_days', 8);

        return max(1, $val);
    }

    /**
     * Return 1-hour rollup retention in days (minimum 1).
     */
    public function rollup1hRetentionDays(): int
    {
        $val = $this->getIntSetting('metrics.retention.rollup_1h_days', 'metrics.retention.rollup_1h_days', 35);

        return max(1, $val);
    }

    /**
     * Return the deduplication window in seconds for cross-tenant admin metric view audits (minimum 60).
     */
    public function auditWindowSeconds(): int
    {
        $val = Cast::int(config('metrics.audit_window_seconds', 900), 900);

        return max(60, $val);
    }

    /**
     * Return the list of UI time ranges supported by currently configured tier retentions.
     *
     * @return list<string>
     */
    public function availableRanges(): array
    {
        $rawHours = $this->rawRetentionHours();
        $rollup5mDays = $this->rollup5mRetentionDays();
        $rollup1hDays = $this->rollup1hRetentionDays();

        $checks = [
            '1h' => $rawHours >= 1,
            '6h' => $rawHours >= self::MIN_RAW_RETENTION_HOURS,
            '24h' => $rollup5mDays >= 1,
            '7d' => $rollup5mDays >= self::MIN_ROLLUP_5M_RETENTION_DAYS,
            '30d' => $rollup1hDays >= self::MIN_ROLLUP_1H_RETENTION_DAYS,
        ];

        $available = [];
        foreach ($checks as $range => $isSupported) {
            if ($isSupported) {
                $available[] = $range;
            }
        }

        return $available;
    }

    /**
     * Check whether a time range is currently available under configured retentions.
     */
    public function isRangeAvailable(string $range): bool
    {
        return in_array($range, $this->availableRanges(), true);
    }

    /**
     * Resolve storage tier, table, bucket width, bucket count, and refresh interval for a query range.
     *
     * @return array{
     *     range: string,
     *     tier: string,
     *     table: string,
     *     duration_seconds: int,
     *     tier_resolution_seconds: int,
     *     bucket_width_seconds: int,
     *     bucket_count: int,
     *     refresh_interval_seconds: int
     * }
     *
     * @throws InvalidArgumentException If the range is unsupported or maxPoints is outside [1, MAX_POINTS_CAP].
     */
    public function resolveQueryPlan(string $range, int $maxPoints = self::MAX_POINTS_CAP): array
    {
        if ($maxPoints < 1 || $maxPoints > self::MAX_POINTS_CAP) {
            throw new InvalidArgumentException(sprintf(
                'max_points must be between 1 and %d, got %d.',
                self::MAX_POINTS_CAP,
                $maxPoints,
            ));
        }

        $interval = $this->intervalSeconds();

        [$duration, $tier, $table, $tierResolution, $refreshInterval] = match ($range) {
            '1h' => [3600, 'raw', 'vm_metric_samples', $interval, $interval],
            '6h' => [21600, 'raw', 'vm_metric_samples', $interval, $interval],
            '24h' => [86400, 'rollup_5m', 'vm_metric_rollups_5m', 300, 300],
            '7d' => [604800, 'rollup_5m', 'vm_metric_rollups_5m', 300, 3600],
            '30d' => [2592000, 'rollup_1h', 'vm_metric_rollups_1h', 3600, 3600],
            default => throw new InvalidArgumentException(sprintf('Unsupported metrics range "%s".', $range)),
        };

        $nativePoints = max(1, intdiv($duration, $tierResolution));
        $multiplier = max(1, (int) ceil($nativePoints / $maxPoints));
        $bucketWidth = $multiplier * $tierResolution;
        $bucketCount = max(1, intdiv($duration, $bucketWidth));

        return [
            'range' => $range,
            'tier' => $tier,
            'table' => $table,
            'duration_seconds' => $duration,
            'tier_resolution_seconds' => $tierResolution,
            'bucket_width_seconds' => $bucketWidth,
            'bucket_count' => $bucketCount,
            'refresh_interval_seconds' => $refreshInterval,
        ];
    }

    /**
     * Resolve an integer setting from SystemSetting with fallback to config().
     */
    private function getIntSetting(string $settingKey, string $configKey, int $default): int
    {
        /** @var mixed $setting */
        $setting = $this->getSystemSettingOrNull($settingKey);

        return is_numeric($setting)
            ? (int) $setting
            : Cast::int(config($configKey, $default), $default);
    }

    /**
     * Read a SystemSetting safely, returning null when the settings table is unavailable.
     */
    private function getSystemSettingOrNull(string $key): mixed
    {
        try {
            return SystemSetting::getSetting($key);
        } catch (Throwable) {
            return null;
        }
    }
}
