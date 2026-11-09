<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Exception thrown when a snapshot with the same name already exists for a virtual machine.
 */
final class DuplicateSnapshotNameException extends SnapshotException
{
    public function __construct(string $name, string $message = '')
    {
        if ($message === '') {
            $message = sprintf('A snapshot with name "%s" already exists for this virtual machine (duplicate snapshot name).', $name);
        }

        parent::__construct($message);
    }
}
