<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Exception thrown when a virtual machine is in an invalid state for a snapshot operation.
 */
final class InvalidVmStateException extends SnapshotException
{
    public function __construct(string $state, string $operation = 'snapshot', string $message = '')
    {
        if ($message === '') {
            $message = sprintf(
                'Cannot perform "%s" while virtual machine is in "%s" state.',
                $operation,
                $state
            );
        }

        parent::__construct($message);
    }
}
