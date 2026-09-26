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
        return Config::intBetween('opening-hours.limits.schedules', 1, 1000, 20);
    }

    public static function rangesPerDay(): int
    {
        return Config::intBetween('opening-hours.limits.ranges_per_day', 1, 100, 12);
    }

    public static function exceptions(): int
    {
        return Config::intBetween('opening-hours.limits.exceptions', 1, 100_000, 1000);
    }

    public static function labelLength(): int
    {
        return Config::intBetween('opening-hours.limits.label_length', 1, 191, 191);
    }

    public static function metaBytes(): int
    {
        return Config::intBetween('opening-hours.limits.meta_bytes', 1, 1_048_576, 4096);
    }
}
