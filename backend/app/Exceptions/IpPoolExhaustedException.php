<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception thrown when no usable IP addresses are available in the target IP pool.
 */
class IpPoolExhaustedException extends RuntimeException
{
    public function __construct(string $message = 'No available IP addresses in the selected pool.')
    {
        parent::__construct($message);
    }
}
