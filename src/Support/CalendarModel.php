<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Support;

use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * The (possibly host-swapped) calendar model from `opening-hours.models.calendar`.
 * A configured class that is not a `Calendar` falls back to the packaged one.
 */
final class CalendarModel
{
    /**
     * @return class-string<Calendar>
     */
    public static function class(): string
    {
        $model = ModelResolver::for('opening-hours.models.calendar', Calendar::class);

        return is_a($model, Calendar::class, true) ? $model : Calendar::class;
    }

    public static function make(): Calendar
    {
        $class = self::class();

        return new $class;
    }
}
