<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Allow Private and Loopback Hypervisor Endpoints
    |--------------------------------------------------------------------------
    |
    | When set to false (default), TlsPeerCertificateFetcher rejects connections
    | to private IP ranges (RFC 1918, RFC 4193) and loopback addresses.
    | Set to true in local development or isolated lab networks.
    |
    */
    'allow_private_endpoints' => (bool) env('HYPERVISOR_ALLOW_PRIVATE_ENDPOINTS', false),

    /*
    |--------------------------------------------------------------------------
    | Interactive Terminal Configuration
    |--------------------------------------------------------------------------
    |
    | Settings governing terminal tickets, idle timeouts, concurrency limits,
    | and maximum WebSocket message payload sizes.
    |
    */
    'terminal' => [
        'ticket_ttl_seconds' => (int) env('TERMINAL_TICKET_TTL_SECONDS', 60),
        'idle_timeout_seconds' => (int) env('TERMINAL_IDLE_TIMEOUT_SECONDS', 900),
        'max_exec_sessions_per_instance' => (int) env('TERMINAL_MAX_EXEC_SESSIONS', 5),
        'max_console_sessions_per_instance' => (int) env('TERMINAL_MAX_CONSOLE_SESSIONS', 1),
        'max_message_bytes' => (int) env('TERMINAL_MAX_MESSAGE_BYTES', 65536),
    ],
];
