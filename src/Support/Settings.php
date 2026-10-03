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
        return Config::integer('opening-hours.search_days', 366, min: 1, max: 3660);
    }

    public static function maxQueryDays(): int
    {
        return Config::integer('opening-hours.max_query_days', 366, min: 1, max: 3660);
    }

    public static function upcomingExceptionsDays(): int
    {
        return Config::integer('opening-hours.api.upcoming_exceptions_days', 60, min: 0, max: 3660);
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
        // An empty env value (`KEY=`) arrives as '' and means "off" too.
        $value = config($key);

        return $value === null || $value === '' ? null : Config::integer($key, 0, min: 0, max: 36500);
    }
}
