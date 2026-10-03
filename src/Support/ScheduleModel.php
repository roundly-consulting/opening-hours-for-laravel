<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Support;

use RoundlyConsulting\OpeningHours\Models\Schedule;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * The (possibly host-swapped) schedule model from `opening-hours.models.schedule`.
 */
final class ScheduleModel
{
    /**
     * @return class-string<Schedule>
     */
    public static function class(): string
    {
        return ModelResolver::for('opening-hours.models.schedule', Schedule::class);
    }
}
