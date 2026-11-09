<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use Throwable;

class FingerprintMismatchException extends ConnectionException
{
    public function __construct(
        protected readonly string $actualFingerprint = '',
        protected readonly string $expectedFingerprint = '',
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        if ($message === '') {
            if ($actualFingerprint !== '' && $expectedFingerprint !== '') {
                $message = sprintf(
                    'Warning: Server certificate fingerprint (%s) does not match the pinned certificate (%s). Possible man-in-the-middle attack or reinstalled server.',
                    $actualFingerprint,
                    $expectedFingerprint
                );
            } else {
                $message = 'Warning: Server certificate does not match the pinned certificate / public key.';
            }
        }

        parent::__construct($message, $code, $previous);
    }

    public function getActualFingerprint(): string
    {
        return $this->actualFingerprint;
    }

    public function getExpectedFingerprint(): string
    {
        return $this->expectedFingerprint;
    }
}
