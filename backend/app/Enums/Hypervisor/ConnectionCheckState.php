<?php

declare(strict_types=1);

namespace App\Enums\Hypervisor;

/**
 * Outcome state of a hypervisor connection and trust verification check.
 */
enum ConnectionCheckState: string
{
    case Ok = 'ok';
    case Unreachable = 'unreachable';
    case FingerprintMismatch = 'fingerprint_mismatch';
    case NotTrusted = 'not_trusted';
    case Error = 'error';
}
