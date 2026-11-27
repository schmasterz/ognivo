<?php

declare(strict_types=1);

namespace App\States\VpsPlanChange;

use App\Models\VpsPlanChange;
use Override;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Base abstract state for VPS plan-change operations.
 *
 * @extends State<VpsPlanChange>
 */
abstract class VpsPlanChangeState extends State
{
    /**
     * Configure allowed VPS plan-change state transitions.
     *
     * Completed, Failed, and Cancelled are terminal states with no outgoing transitions.
     */
    #[Override]
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Pending::class)
            ->allowTransition(AwaitingAuthorization::class, Pending::class)
            ->allowTransition(AwaitingAuthorization::class, Failed::class)
            ->allowTransition(AwaitingAuthorization::class, Cancelled::class)
            ->allowTransition(Pending::class, Applying::class)
            ->allowTransition(Pending::class, Cancelled::class)
            ->allowTransition(Pending::class, Failed::class)
            ->allowTransition(Applying::class, Completed::class)
            ->allowTransition(Applying::class, Failed::class)
            ->allowTransition(Applying::class, ReconciliationRequired::class)
            ->allowTransition(ReconciliationRequired::class, Completed::class)
            ->allowTransition(ReconciliationRequired::class, Failed::class)
            ->allowTransition(ReconciliationRequired::class, Applying::class);
    }

    /**
     * Return all valid VPS plan-change state morph values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            Pending::$name,
            AwaitingAuthorization::$name,
            Applying::$name,
            Completed::$name,
            Failed::$name,
            Cancelled::$name,
            ReconciliationRequired::$name,
        ];
    }

    /**
     * Return morph values for states that represent an active plan change holding a reservation.
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [
            Pending::$name,
            AwaitingAuthorization::$name,
            Applying::$name,
            ReconciliationRequired::$name,
        ];
    }

    /**
     * Determine whether this state represents an in-flight / active plan change.
     */
    public function isActive(): bool
    {
        return false;
    }

    /**
     * Alias for isActive(): determine whether this state holds a concurrency/capacity reservation.
     */
    public function holdsReservation(): bool
    {
        return $this->isActive();
    }

    /**
     * Determine whether this state is terminal (no further transitions possible).
     */
    public function isTerminal(): bool
    {
        return ! $this->isActive();
    }

    /**
     * Determine whether a plan change in this state may be cancelled by the requester.
     */
    public function canCancel(): bool
    {
        return false;
    }
}
