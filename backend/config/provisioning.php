<?php

declare(strict_types=1);

return [
    'network_wait_seconds' => (int) env('PROVISION_NETWORK_WAIT_SECONDS', 120),
    'poll_interval_seconds' => (int) env('PROVISION_POLL_INTERVAL_SECONDS', 10),
    'job_deadline_seconds' => (int) env('PROVISION_JOB_DEADLINE_SECONDS', 900),
    'cpu_overcommit' => (float) env('PROVISION_CPU_OVERCOMMIT', 1.0),
    'ram_overcommit' => (float) env('PROVISION_RAM_OVERCOMMIT', 1.0),
];
