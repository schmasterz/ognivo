<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Plan Change Authorization Timeouts
    |--------------------------------------------------------------------------
    |
    | Timeout for external/webhook authorizer HTTP calls and maximum lifetime
    | for pending plan change authorizations before expiration.
    |
    */
    'authorizer_timeout_seconds' => (int) env('VPS_AUTHORIZER_TIMEOUT_SECONDS', 3),
    'authorization_timeout_minutes' => (int) env('VPS_AUTHORIZATION_TIMEOUT_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Plan Change Application & Stale Recovery Timings
    |--------------------------------------------------------------------------
    |
    | Hypervisor resource update wait timeout, overall apply deadline, and
    | stale-job recovery thresholds for pending and applying plan changes.
    |
    */
    'resource_update_wait_seconds' => (int) env('VPS_RESOURCE_UPDATE_WAIT_SECONDS', 30),
    'apply_deadline_minutes' => (int) env('VPS_APPLY_DEADLINE_MINUTES', 20),
    'pending_stale_minutes' => (int) env('VPS_PENDING_STALE_MINUTES', 15),
    'applying_stale_minutes' => (int) env('VPS_APPLYING_STALE_MINUTES', 30),
];
