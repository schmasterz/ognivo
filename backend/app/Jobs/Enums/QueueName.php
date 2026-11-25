<?php

declare(strict_types=1);

namespace App\Jobs\Enums;

enum QueueName: string
{
    case High = 'high';
    case Metrics = 'metrics';
    case Default = 'default';
    case Heavy = 'heavy';

    /**
     * Get all queue names as an array of strings.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
