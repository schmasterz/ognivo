<?php

declare(strict_types=1);

namespace App\States\VpsPlanVersion;

use App\Models\VpsPlanVersion;
use Override;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Base abstract state for VPS Plan Versions.
 *
 * @extends State<VpsPlanVersion>
 */
abstract class VpsPlanVersionState extends State
{
    /**
     * Configure allowed VPS Plan Version state transitions.
     *
     * Superseded is a terminal state with no outgoing transitions.
     */
    #[Override]
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Draft::class)
            ->allowTransition(Draft::class, Published::class)
            ->allowTransition(Published::class, Superseded::class);
    }

    /**
     * Return all valid VPS Plan Version state morph values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            Draft::$name,
            Published::$name,
            Superseded::$name,
        ];
    }
}
