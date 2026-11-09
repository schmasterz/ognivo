<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Exception thrown when creating a snapshot would exceed the user's snapshot quota.
 */
final class SnapshotQuotaExceededException extends SnapshotException
{
    public function __construct(string $message = 'Snapshot count quota exceeded.')
    {
        parent::__construct($message);
    }
}
