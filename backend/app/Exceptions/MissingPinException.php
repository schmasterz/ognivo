<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when an operational connection is attempted to a non-fake host
 * without a configured public key pin (fail-closed).
 */
class MissingPinException extends RuntimeException
{
    public function __construct(
        string $message = 'Host is missing required public key pin.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
