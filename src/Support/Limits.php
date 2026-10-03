<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Support;

use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * The `opening-hours.limits.*` guards against oversized definitions and queries.
 */
final class Limits
{
    public static function schedules(): int
    {
        return Config::integer('opening-hours.limits.schedules', 20, min: 1, max: 1000);
    }

    public static function rangesPerDay(): int
    {
        return Config::integer('opening-hours.limits.ranges_per_day', 12, min: 1, max: 100);
    }

    public static function exceptions(): int
    {
        // An exception's list position is stored in a smallint column (max 32 767 on PostgreSQL).
        return Config::integer('opening-hours.limits.exceptions', 1000, min: 1, max: 32_767);
    }

    public static function labelLength(): int
    {
        return Config::integer('opening-hours.limits.label_length', 191, min: 1, max: 191);
    }

    public static function metaBytes(): int
    {
        return Config::integer('opening-hours.limits.meta_bytes', 4096, min: 1, max: 1_048_576);
    }

    public static function calendars(): int
    {
        return Config::integer('opening-hours.limits.calendars', 16, min: 1, max: 1000);
    }

    public static function busyPeriods(): int
    {
        return Config::integer('opening-hours.limits.busy_periods', 10000, min: 1, max: 1_000_000);
    }

    public static function slots(): int
    {
        return Config::integer('opening-hours.limits.slots', 2000, min: 1, max: 100_000);
    }
}
