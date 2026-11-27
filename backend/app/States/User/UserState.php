<?php

declare(strict_types=1);

namespace App\States\User;

use App\Models\User;
use Override;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Base abstract state for User accounts.
 *
 * @extends State<User>
 */
abstract class UserState extends State
{
    /**
     * Configure state transitions.
     */
    #[Override]
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Active::class)
            ->allowTransition(Pending::class, Active::class)
            ->allowTransition(Pending::class, Suspended::class)
            ->allowTransition(Pending::class, Inactive::class)
            ->allowTransition(Pending::class, Deleting::class)
            ->allowTransition(Active::class, Pending::class)
            ->allowTransition(Active::class, Suspended::class)
            ->allowTransition(Active::class, Inactive::class)
            ->allowTransition(Active::class, Deleting::class)
            ->allowTransition(Suspended::class, Active::class)
            ->allowTransition(Suspended::class, Inactive::class)
            ->allowTransition(Suspended::class, Deleting::class)
            ->allowTransition(Inactive::class, Active::class)
            ->allowTransition(Inactive::class, Suspended::class)
            ->allowTransition(Inactive::class, Deleting::class)
            ->allowTransition(Deleting::class, DeletionFailed::class)
            ->allowTransition(DeletionFailed::class, Deleting::class)
            ->allowTransition(DeletionFailed::class, Inactive::class)
            ->allowTransition(DeletionFailed::class, Suspended::class);
    }

    /**
     * Return all valid user state morph values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            Pending::$name,
            Active::$name,
            Suspended::$name,
            Inactive::$name,
            Deleting::$name,
            DeletionFailed::$name,
        ];
    }

    /**
     * Return user state morph values assignable via administrative create/update endpoints.
     *
     * @return list<string>
     */
    public static function assignableValues(): array
    {
        return [
            Active::$name,
            Suspended::$name,
            Inactive::$name,
            Pending::$name,
        ];
    }

    /**
     * Resolve the translation message key explaining why a non-active account is blocked.
     */
    public function inactiveMessageKey(): string
    {
        return match (true) {
            $this instanceof Suspended => 'messages.auth.account_suspended',
            $this instanceof Pending => 'messages.auth.account_pending_verification',
            default => 'messages.auth.account_inactive',
        };
    }
}
