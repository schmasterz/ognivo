<?php

declare(strict_types=1);

namespace App\States\Vm;

use App\Models\VirtualMachine;
use Override;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Base abstract state for Virtual Machines.
 *
 * @extends State<VirtualMachine>
 */
abstract class VmState extends State
{
    /**
     * Configure state transitions.
     */
    #[Override]
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Provisioning::class)
            ->allowTransition(Provisioning::class, Running::class)
            ->allowTransition(Provisioning::class, Stopped::class)
            ->allowTransition(Provisioning::class, Suspended::class)
            ->allowTransition(Provisioning::class, Failed::class)
            ->allowTransition(Provisioning::class, Deleting::class)
            ->allowTransition(Provisioning::class, Deleted::class)
            ->allowTransition(Running::class, Provisioning::class)
            ->allowTransition(Running::class, Stopped::class)
            ->allowTransition(Running::class, Suspended::class)
            ->allowTransition(Running::class, Deleting::class)
            ->allowTransition(Running::class, Deleted::class)
            ->allowTransition(Running::class, Failed::class)
            ->allowTransition(Running::class, Running::class)
            ->allowTransition(Stopped::class, Provisioning::class)
            ->allowTransition(Stopped::class, Running::class)
            ->allowTransition(Stopped::class, Stopped::class)
            ->allowTransition(Stopped::class, Suspended::class)
            ->allowTransition(Stopped::class, Deleting::class)
            ->allowTransition(Stopped::class, Deleted::class)
            ->allowTransition(Stopped::class, Failed::class)
            ->allowTransition(Suspended::class, Provisioning::class)
            ->allowTransition(Suspended::class, Running::class)
            ->allowTransition(Suspended::class, Stopped::class)
            ->allowTransition(Suspended::class, Suspended::class)
            ->allowTransition(Suspended::class, Deleting::class)
            ->allowTransition(Suspended::class, Deleted::class)
            ->allowTransition(Suspended::class, Failed::class)
            ->allowTransition(Deleting::class, Deleted::class)
            ->allowTransition(Deleting::class, Failed::class)
            ->allowTransition(Failed::class, Provisioning::class)
            ->allowTransition(Failed::class, Failed::class)
            ->allowTransition(Failed::class, Deleting::class)
            ->allowTransition(Failed::class, Deleted::class);
    }
}
