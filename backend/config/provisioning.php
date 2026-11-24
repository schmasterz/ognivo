<?php

declare(strict_types=1);

return [
    'network_wait_seconds' => (int) env('PROVISION_NETWORK_WAIT_SECONDS', 120),
    'poll_interval_seconds' => (int) env('PROVISION_POLL_INTERVAL_SECONDS', 10),
    'job_deadline_seconds' => (int) env('PROVISION_JOB_DEADLINE_SECONDS', 900),

    /*
    |--------------------------------------------------------------------------
    | Host Capacity Reserves
    |--------------------------------------------------------------------------
    |
    | Absolute capacity reserved for host OS / overhead before provisioning.
    | Formula: committed + requested <= total * overcommit - reserve
    |
    */
    'host_cpu_reserve_cores' => (int) env('PROVISION_HOST_CPU_RESERVE_CORES', 0),
    'host_ram_reserve_mb' => (int) env('PROVISION_HOST_RAM_RESERVE_MB', 1024),
    'host_disk_reserve_gb' => (int) env('PROVISION_HOST_DISK_RESERVE_GB', 0),

    /*
    |--------------------------------------------------------------------------
    | Default Storage Pool
    |--------------------------------------------------------------------------
    |
    | Default Incus/LXD storage pool for provisioning instances and calculating
    | host disk capacity.
    |
    */
    'storage_pool' => env('PROVISION_STORAGE_POOL', 'default'),
];
