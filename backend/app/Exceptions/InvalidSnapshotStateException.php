<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Exception thrown when a snapshot is in an invalid status for the requested operation.
 */
final class InvalidSnapshotStateException extends SnapshotException
{
    public function __construct(
        public readonly string $status,
        public readonly string $operation = 'restore',
        string $message = '',
    ) {
        if ($message === '') {
            $message = sprintf(
                'Cannot %s snapshot while in "%s" status.',
                $operation,
                $status
            );
        }

        parent::__construct($message);
    }
}
