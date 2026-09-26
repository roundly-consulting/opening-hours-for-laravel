<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * How a date window repeats: `None` is a one-off window on real dates, `Yearly`
 * repeats every year on the same month/day span.
 */
enum Recurrence: string
{
    use Helpers;

    case None = 'none';
    case Yearly = 'yearly';
}
