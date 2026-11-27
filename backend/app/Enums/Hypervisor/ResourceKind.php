<?php

declare(strict_types=1);

namespace App\Enums\Hypervisor;

/**
 * Individual instance resource dimension evaluated during resize assessment.
 */
enum ResourceKind: string
{
    case Cpu = 'cpu';
    case Ram = 'ram';
    case Disk = 'disk';
    case NetDownload = 'net_download';
    case NetUpload = 'net_upload';
}
