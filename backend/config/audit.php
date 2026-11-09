<?php

declare(strict_types=1);

/**
 * Build the map of HMAC sealing keys.
 * Includes the current key and any previous historical keys configured for rotation.
 *
 * Previous keys can be supplied via AUDIT_PREVIOUS_HMAC_KEYS as either JSON
 * (e.g. '{"v0":"key0","v1":"key1"}') or comma-separated pairs ('v0:key0,v1:key1').
 */
$currentKeyId = (string) env('AUDIT_HMAC_KEY_ID', 'v1');
$currentKey = (string) env('AUDIT_HMAC_KEY', '');

$hmacKeys = [
    $currentKeyId => $currentKey,
];

$previousKeysEnv = env('AUDIT_PREVIOUS_HMAC_KEYS');
if (is_string($previousKeysEnv) && trim($previousKeysEnv) !== '') {
    $trimmed = trim($previousKeysEnv);
    if (str_starts_with($trimmed, '{')) {
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            foreach ($decoded as $k => $v) {
                if (is_string($k) && is_string($v) && $k !== '') {
                    $hmacKeys[$k] = $v;
                }
            }
        }
    } else {
        foreach (explode(',', $trimmed) as $pair) {
            $parts = explode(':', trim($pair), 2);
            if (count($parts) === 2 && trim($parts[0]) !== '') {
                $hmacKeys[trim($parts[0])] = trim($parts[1]);
            }
        }
    }
}

// Ensure the current active key always takes precedence in the map
$hmacKeys[$currentKeyId] = $currentKey;

return [
    /*
    |--------------------------------------------------------------------------
    | Strict Grants Mode
    |--------------------------------------------------------------------------
    |
    | false in development (main DB user retains normal privileges).
    | true in production (app DB user is restricted to INSERT/SELECT on audit tables).
    |
    */
    'strict_grants' => filter_var(env('AUDIT_STRICT_GRANTS', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Dedicated Database Connections
    |--------------------------------------------------------------------------
    |
    | Separate credentials for audit logging, maintenance pruning/pseudonymisation,
    | and migration execution under strict grants.
    |
    */
    'database' => [
        'writer' => [
            'username' => env('AUDIT_DB_USERNAME', 'audit_writer'),
            'password' => env('AUDIT_DB_PASSWORD', ''),
        ],
        'maintenance' => [
            'username' => env('AUDIT_MAINT_DB_USERNAME', 'audit_maintenance'),
            'password' => env('AUDIT_MAINT_DB_PASSWORD', ''),
        ],
        'migration' => [
            'username' => env('DB_MIGRATION_USERNAME', 'migration_user'),
            'password' => env('DB_MIGRATION_PASSWORD', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sealing HMAC Key & Key Rotation Map
    |--------------------------------------------------------------------------
    |
    | Seals in audit_seals are signed with an HMAC. The key_id column stores the ID
    | of the key used. When rotating keys:
    | - New seals are signed with the active key (current_key_id / current_key).
    | - Historical seals are verified against their corresponding key in 'keys'.
    |
    */
    'hmac' => [
        'current_key_id' => $currentKeyId,
        'current_key' => $currentKey,
        'keys' => $hmacKeys,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pseudonymisation Salt
    |--------------------------------------------------------------------------
    |
    | Used to hash user identifiers when scrubbing PII upon user deletion without
    | breaking audit hash chain verification.
    |
    */
    'pseudonym_salt' => env('AUDIT_PSEUDONYM_SALT', ''),

    /*
    |--------------------------------------------------------------------------
    | Audit Tunables
    |--------------------------------------------------------------------------
    |
    | - retention_days: Keep audit logs for 400 days by default.
    | - seal_delay_seconds: Minimum age (120 seconds) before rows are sealed.
    | - attempted_email_days: Unauthenticated login email retention (30 days).
    | - log_stderr: Duplicate audit logs to stderr.
    |
    */
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 400),
    'seal_delay_seconds' => (int) env('AUDIT_SEAL_DELAY_SECONDS', 120),
    'attempted_email_days' => (int) env('AUDIT_ATTEMPTED_EMAIL_DAYS', 30),
    'log_stderr' => filter_var(env('AUDIT_LOG_STDERR', false), FILTER_VALIDATE_BOOLEAN),
];
