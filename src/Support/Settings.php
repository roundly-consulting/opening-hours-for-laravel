<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Support;

use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\Enums\WeekMode;
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

    public static function defaultCalendar(): string
    {
        return Config::requireString('opening-hours.default_calendar');
    }

    public static function deleteWithOwner(): bool
    {
        return Config::boolean('opening-hours.delete_with_owner', true);
    }

    public static function exposeMeta(): bool
    {
        return Config::boolean('opening-hours.api.expose_meta');
    }

    public static function weekMode(): WeekMode
    {
        return Config::enum('opening-hours.api.week_mode', WeekMode::class);
    }

    public static function pruneExceptionsAfterDays(): ?int
    {
        return self::nullableDays('opening-hours.prune.exceptions_after_days');
    }

    public static function pruneTrashedAfterDays(): ?int
    {
        return self::nullableDays('opening-hours.prune.trashed_after_days');
    }

    private static function nullableDays(string $key): ?int
    {
        // Read raw first: the toolkit accessor maps null to its default, and null means "off" here.
        return config($key) === null ? null : Config::intBetween($key, 0, 36500, 0);
    }
}
