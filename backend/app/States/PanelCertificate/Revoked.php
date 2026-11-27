<?php

declare(strict_types=1);

namespace App\States\PanelCertificate;

/**
 * State representing a permanently revoked panel mTLS certificate.
 */
final class Revoked extends PanelCertificateState
{
    public static string $name = 'revoked';
}
