<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Support;

use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Validated reads of `config/opening-hours.php`. Invalid values throw the
 * toolkit's `InvalidConfigurationException` instead of silently misbehaving.
 */
final class Settings
{
    public static function firstDayOfWeek(): Weekday
    {
        return Config::enum('opening-hours.first_day_of_week', Weekday::class);
    }

    public static function searchDays(): int
    {
        return Config::intBetween('opening-hours.search_days', 1, 3660, 366);
    }

    public static function maxQueryDays(): int
    {
        return Config::intBetween('opening-hours.max_query_days', 1, 3660, 366);
    }

    public static function upcomingExceptionsDays(): int
    {
        return Config::intBetween('opening-hours.api.upcoming_exceptions_days', 0, 3660, 60);
    }
}
