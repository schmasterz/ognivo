<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Exception thrown when a snapshot name is not a valid slug.
 */
final class InvalidSnapshotNameException extends SnapshotException
{
    public function __construct(string $name, string $message = '')
    {
        if ($message === '') {
            $message = sprintf(
                'Invalid snapshot name "%s": name must be a non-empty slug (lowercase alphanumeric characters, hyphens, or underscores).',
                $name
            );
        }

        parent::__construct($message);
    }
}
