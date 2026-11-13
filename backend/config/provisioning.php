<?php

declare(strict_types=1);

return [
    'network_wait_seconds' => (int) env('PROVISION_NETWORK_WAIT_SECONDS', 120),
    'poll_interval_seconds' => (int) env('PROVISION_POLL_INTERVAL_SECONDS', 10),
    'job_deadline_seconds' => (int) env('PROVISION_JOB_DEADLINE_SECONDS', 900),

    /*
    |--------------------------------------------------------------------------
    | Compute & Storage Overcommit Multipliers
    |--------------------------------------------------------------------------
    |
    | Factors by which available host resources can be committed across VMs.
    | 1.0 represents strictly no overcommit.
    |
    */
    'cpu_overcommit' => (float) env('PROVISION_CPU_OVERCOMMIT', 1.0),
    'ram_overcommit' => (float) env('PROVISION_RAM_OVERCOMMIT', 1.0),
    'disk_overcommit' => (float) env('PROVISION_DISK_OVERCOMMIT', 1.0),

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
