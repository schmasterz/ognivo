<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a hypervisor host is unreachable or fails to respond with a valid status.
 */
final class DriverUnavailableException extends RuntimeException {}
