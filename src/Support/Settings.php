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

    /**
     * The definition-cache key prefix: `opening-hours` when unset, otherwise a non-empty string.
     */
    public static function cachePrefix(): string
    {
        return self::name('opening-hours.cache.prefix') ?? 'opening-hours';
    }

    /**
     * The definition-cache store, or null for the default store.
     */
    public static function cacheStore(): ?string
    {
        return self::name('opening-hours.cache.store');
    }

    /**
     * The queue connection for materialization jobs, or null for the default.
     */
    public static function materializeConnection(): ?string
    {
        return self::name('opening-hours.materialize.connection');
    }

    /**
     * The queue for materialization jobs, or null for the default.
     */
    public static function materializeQueue(): ?string
    {
        return self::name('opening-hours.materialize.queue');
    }

    /**
     * A named resource (store, queue, connection, prefix): null when unset, otherwise a
     * non-empty string — a blank or non-string value throws instead of meaning the default.
     */
    private static function name(string $key): ?string
    {
        return config($key) === null ? null : Config::requireString($key);
    }

    private static function nullableDays(string $key): ?int
    {
        // Read raw first: the toolkit accessor maps null to its default, and null means "off" here.
        // An empty env value (`KEY=`) arrives as '' and means "off" too.
        $value = config($key);

        return $value === null || $value === '' ? null : Config::integer($key, 0, min: 0, max: 36500);
    }
}
