<?php

declare(strict_types=1);

namespace App\States\Snapshot;

use App\Models\VmSnapshot;
use Override;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Base abstract state for VM Snapshots.
 *
 * @extends State<VmSnapshot>
 */
abstract class SnapshotState extends State
{
    /**
     * Configure state transitions.
     */
    #[Override]
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Creating::class)
            ->allowTransition(Creating::class, Ready::class)
            ->allowTransition(Creating::class, Failed::class)
            ->allowTransition(Ready::class, Restoring::class)
            ->allowTransition(Ready::class, Deleting::class)
            ->allowTransition(Ready::class, Failed::class)
            ->allowTransition(Restoring::class, Ready::class)
            ->allowTransition(Restoring::class, Failed::class)
            ->allowTransition(Deleting::class, Failed::class)
            ->allowTransition(Failed::class, Deleting::class)
            ->allowTransition(Failed::class, Failed::class);
    }
}
