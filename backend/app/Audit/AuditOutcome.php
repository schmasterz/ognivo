<?php

declare(strict_types=1);

namespace App\Audit;

enum AuditOutcome: string
{
    case Success = 'success';
    case Denied = 'denied';
    case Failed = 'failed';
}
