<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * Which seven days the status resource's `week` block shows: the next seven
 * dates, or the calendar week (from `first_day_of_week`) containing today.
 */
enum WeekMode: string
{
    use Helpers;

    case Upcoming = 'upcoming';
    case CalendarWeek = 'calendar_week';
}
