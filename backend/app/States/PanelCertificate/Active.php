<?php

declare(strict_types=1);

namespace App\States\PanelCertificate;

/**
 * State representing the currently active panel mTLS certificate.
 */
final class Active extends PanelCertificateState
{
    public static string $name = 'active';
}
