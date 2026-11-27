<?php

declare(strict_types=1);

namespace App\DTO\Hypervisor;

/**
 * Execution state of an asynchronous hypervisor operation.
 */
enum OperationState: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
