<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Support;

use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 */
final class CalendarModel
{
    /**
     * @return class-string<Calendar>
     */
    public static function class(): string
    {
        return ModelResolver::for('opening-hours.models.calendar', Calendar::class);
    }

    public static function make(): Calendar
    {
        $class = self::class();

        return new $class;
    }
}
