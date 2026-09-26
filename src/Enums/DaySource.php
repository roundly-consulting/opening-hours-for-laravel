<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * Where the hours of a date came from.
 */
enum DaySource: string
{
    use Helpers;

    case Schedule = 'schedule';
    case Exception = 'exception';
    case Dynamic = 'dynamic';
    case None = 'none';
}
