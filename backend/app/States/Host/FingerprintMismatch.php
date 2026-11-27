<?php

declare(strict_types=1);

namespace App\States\Host;

/**
 * State representing a compute host whose TLS certificate fingerprint or public key pin mismatches.
 */
final class FingerprintMismatch extends HostState
{
    public static string $name = 'fingerprint_mismatch';
}
