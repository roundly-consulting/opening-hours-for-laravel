<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Support;

use DateTimeZone;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidTimezoneException;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * Resolves the timezone a calendar is evaluated in:
 * calendar → owner hook → `opening-hours.timezone` → `app.timezone`.
 * Every candidate is validated against the IANA list; fixed offsets are rejected.
 */
final class TimezoneResolver
{
    /** @var array<string, true>|null */
    private static ?array $identifiers = null;

    public static function isValid(string $timezone): bool
    {
        self::$identifiers ??= array_fill_keys(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);

        return isset(self::$identifiers[$timezone]);
    }

    /**
     * @throws InvalidTimezoneException
     */
    public static function validate(DateTimeZone|string $timezone): DateTimeZone
    {
        $name = $timezone instanceof DateTimeZone ? $timezone->getName() : $timezone;

        if (! self::isValid($name)) {
            throw InvalidTimezoneException::unknown($name);
        }

        return $timezone instanceof DateTimeZone ? $timezone : new DateTimeZone($name);
    }

    /**
     * The first non-empty candidate, falling back to the configured defaults.
     */
    public static function resolve(DateTimeZone|string|null ...$candidates): DateTimeZone
    {
        foreach ($candidates as $candidate) {
            if ($candidate instanceof DateTimeZone || (is_string($candidate) && $candidate !== '')) {
                return self::validate($candidate);
            }
        }

        return self::validate(self::fallback());
    }

    public static function fallback(): string
    {
        $configured = config('opening-hours.timezone');

        // Unset or empty (`OPENING_HOURS_TIMEZONE=`) means the app's zone; a string is
        // validated by the caller; anything else is a config error, not a quiet fallback.
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if ($configured !== null && $configured !== '') {
            throw InvalidConfigurationException::notAString('opening-hours.timezone', $configured);
        }

        $app = config('app.timezone');

        return is_string($app) && $app !== '' ? $app : 'UTC';
    }
}
