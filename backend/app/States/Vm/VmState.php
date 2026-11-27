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
     * Configure allowed VM state transitions.
     *
     * Pruned relative to the initial near-complete mesh:
     * - Self-transitions (Running -> Running, Stopped -> Stopped, Suspended -> Suspended, Failed -> Failed):
     *   callers must guard with equals(...) before transitionTo().
     * - Provisioning -> Stopped / Suspended / Deleted: provisioning only finishes in Running, Failed,
     *   or Deleting (forced user-workload cleanup); prevents premature Stopped/Deleted transitions from
     *   hypervisor events during initial provisioning or reinstall.
     * - Deleted -> *: terminal state with no outgoing transitions.
     * - Deleting -> Running / Stopped / Suspended / Provisioning: only transitions to Deleted or Failed.
     * - Failed -> Running / Stopped / Suspended: must go through Provisioning (reinstall) or Deleting/Deleted.
     */
    #[Override]
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Provisioning::class)
            ->allowTransition(Provisioning::class, Running::class)
            ->allowTransition(Provisioning::class, Deleting::class)
            ->allowTransition(Provisioning::class, Failed::class)
            ->allowTransition(Running::class, Provisioning::class)
            ->allowTransition(Running::class, Stopped::class)
            ->allowTransition(Running::class, Suspended::class)
            ->allowTransition(Running::class, Deleting::class)
            ->allowTransition(Running::class, Deleted::class)
            ->allowTransition(Running::class, Failed::class)
            ->allowTransition(Stopped::class, Provisioning::class)
            ->allowTransition(Stopped::class, Running::class)
            ->allowTransition(Stopped::class, Suspended::class)
            ->allowTransition(Stopped::class, Deleting::class)
            ->allowTransition(Stopped::class, Deleted::class)
            ->allowTransition(Stopped::class, Failed::class)
            ->allowTransition(Suspended::class, Provisioning::class)
            ->allowTransition(Suspended::class, Running::class)
            ->allowTransition(Suspended::class, Stopped::class)
            ->allowTransition(Suspended::class, Deleting::class)
            ->allowTransition(Suspended::class, Deleted::class)
            ->allowTransition(Suspended::class, Failed::class)
            ->allowTransition(Deleting::class, Deleted::class)
            ->allowTransition(Deleting::class, Failed::class)
            ->allowTransition(Failed::class, Provisioning::class)
            ->allowTransition(Failed::class, Deleting::class)
            ->allowTransition(Failed::class, Deleted::class);
    }

    /**
     * Return all valid VM state morph values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            Provisioning::$name,
            Running::$name,
            Stopped::$name,
            Suspended::$name,
            Deleting::$name,
            Deleted::$name,
            Failed::$name,
        ];
    }
}
