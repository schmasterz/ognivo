<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * Base domain exception for virtual machine snapshot operations.
 */
class SnapshotException extends DomainException {}
