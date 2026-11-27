<?php

declare(strict_types=1);

namespace App\States\Host;

use App\Models\Host;
use Override;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Base abstract state for virtualization compute Hosts.
 *
 * @extends State<Host>
 */
abstract class HostState extends State
{
    /**
     * Configure state transitions.
     */
    #[Override]
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Ok::class)
            ->allowTransition(Ok::class, Unreachable::class)
            ->allowTransition(Ok::class, NotTrusted::class)
            ->allowTransition(Ok::class, FingerprintMismatch::class)
            ->allowTransition(Ok::class, Error::class)
            ->allowTransition(Unreachable::class, Ok::class)
            ->allowTransition(Unreachable::class, NotTrusted::class)
            ->allowTransition(Unreachable::class, FingerprintMismatch::class)
            ->allowTransition(Unreachable::class, Error::class)
            ->allowTransition(Error::class, Ok::class)
            ->allowTransition(Error::class, Unreachable::class)
            ->allowTransition(Error::class, NotTrusted::class)
            ->allowTransition(Error::class, FingerprintMismatch::class)
            ->allowTransition(NotTrusted::class, Ok::class)
            ->allowTransition(NotTrusted::class, FingerprintMismatch::class)
            ->allowTransition(NotTrusted::class, Error::class)
            ->allowTransition(FingerprintMismatch::class, Ok::class)
            ->allowTransition(FingerprintMismatch::class, NotTrusted::class)
            ->allowTransition(FingerprintMismatch::class, Error::class);
    }

    /**
     * Return all valid host state morph values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            Ok::$name,
            Unreachable::$name,
            NotTrusted::$name,
            FingerprintMismatch::$name,
            Error::$name,
        ];
    }

    /**
     * Transition to the target state when permitted, or assign directly on unsaved models.
     *
     * @param  class-string<HostState>  $targetStateClass
     */
    public function transitionIfAllowed(string $targetStateClass): bool
    {
        if ($this->equals($targetStateClass) || ! $this->canTransitionTo($targetStateClass)) {
            return false;
        }

        /** @var Host $host */
        $host = $this->getModel();
        if (! $host->exists) {
            /** @psalm-suppress UnsafeInstantiation */
            $host->status = new $targetStateClass($host);

            return true;
        }

        $this->transitionTo($targetStateClass);

        return true;
    }
}
