<?php

declare(strict_types=1);

namespace App\States\PanelCertificate;

/**
 * State representing a rotated panel mTLS certificate awaiting host re-trust before revocation.
 */
final class Retiring extends PanelCertificateState
{
    public static string $name = 'retiring';
}
