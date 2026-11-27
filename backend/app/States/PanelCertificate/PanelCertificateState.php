<?php

declare(strict_types=1);

namespace App\States\PanelCertificate;

use App\Models\PanelCertificate;
use Override;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Base abstract state for Panel mTLS client certificates.
 *
 * @extends State<PanelCertificate>
 */
abstract class PanelCertificateState extends State
{
    /**
     * Configure state transitions.
     */
    #[Override]
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Active::class)
            ->allowTransition(Active::class, Retiring::class)
            ->allowTransition(Retiring::class, Revoked::class);
    }
}
