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
];
