<?php

declare(strict_types=1);

namespace App\States\VpsPlan;

use App\Models\VpsPlan;
use Override;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Base abstract state for VPS Plans.
 *
 * @extends State<VpsPlan>
 */
abstract class VpsPlanState extends State
{
    /**
     * Configure allowed VPS Plan state transitions.
     */
    #[Override]
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Draft::class)
            ->allowTransition(Draft::class, Published::class)
            ->allowTransition(Published::class, Draft::class)
            ->allowTransition(Published::class, Archived::class)
            ->allowTransition(Draft::class, Archived::class)
            ->allowTransition(Archived::class, Draft::class);
    }

    /**
     * Return all valid VPS Plan state morph values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            Draft::$name,
            Published::$name,
            Archived::$name,
        ];
    }
}
