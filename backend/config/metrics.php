<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Instance Metrics Feature Flag
    |--------------------------------------------------------------------------
    |
    | Master toggle for instance metrics collection and query API (FR-11).
    | When false, no hypervisor metrics requests are made and the UI tab is
    | hidden, while rollup and retention jobs continue to maintain local data.
    |
    */
    'enabled' => (bool) env('METRICS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Collection Interval & Hypervisor Timeout
    |--------------------------------------------------------------------------
    |
    | Interval in seconds between collection cycles (allowed: 30..300, default: 60)
    | and per-node HTTP scrape timeout in seconds (maximum: 10).
    |
    */
    'interval_seconds' => (int) env('METRICS_INTERVAL_SECONDS', 60),
    'request_timeout_seconds' => (int) env('METRICS_REQUEST_TIMEOUT_SECONDS', 10),

    /*
    |--------------------------------------------------------------------------
    | Three-Tier Retention Periods
    |--------------------------------------------------------------------------
    |
    | Raw samples serve 1h and 6h ranges (minimum 6h, default 12h).
    | 5-minute rollups serve 24h and 7d ranges (minimum 8d, default 8d).
    | 1-hour rollups serve 30d range (minimum 35d, default 35d).
    |
    */
    'retention' => [
        'raw_hours' => (int) env('METRICS_RETENTION_RAW_HOURS', 12),
        'rollup_5m_days' => (int) env('METRICS_RETENTION_5M_DAYS', 8),
        'rollup_1h_days' => (int) env('METRICS_RETENTION_1H_DAYS', 35),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cross-Tenant Audit Window & Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Window in seconds for deduplicating cross-tenant admin metric view audit
    | records (FR-14, default 900s = 15m), and per-user query API rate limit.
    |
    */
    'audit_window_seconds' => (int) env('METRICS_AUDIT_WINDOW_SECONDS', 900),
    'rate_limit_per_minute' => (int) env('METRICS_RATE_LIMIT_PER_MINUTE', 60),
];
