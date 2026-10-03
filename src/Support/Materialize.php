<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RoundlyConsulting\OpeningHours\Contracts\OpeningHoursOwner;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\OpeningHours;
use RoundlyConsulting\OpeningHours\OpeningHoursManager;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Settings and helpers of the opt-in materialized-interval layer.
 *
 * @internal
 */
final class Materialize
{
    public static function enabled(): bool
    {
        return Config::boolean('opening-hours.materialize.enabled');
    }

    public static function daysAhead(): int
    {
        return Config::integer('opening-hours.materialize.days_ahead', 60, min: 1, max: 3660);
    }

    public static function daysBehind(): int
    {
        return Config::integer('opening-hours.materialize.days_behind', 1, min: 0, max: 3660);
    }

    public static function connection(): ?string
    {
        $value = config('opening-hours.materialize.connection');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function queue(): ?string
    {
        $value = config('opening-hours.materialize.queue');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The calendar evaluated like the owner sees it (owner timezone hook and
     * dynamic providers included when the owner is an `OpeningHoursOwner`).
     */
    public static function hoursFor(Calendar $calendar): OpeningHours
    {
        $ownerClass = Relation::getMorphedModel($calendar->owner_type) ?? $calendar->owner_type;
        $owner = class_exists($ownerClass) ? $calendar->owner()->first() : null;

        if ($owner instanceof Model && $owner instanceof OpeningHoursOwner) {
            return app(OpeningHoursManager::class)->for($owner, $calendar->key);
        }

        return OpeningHours::make($calendar->toData(), TimezoneResolver::resolve($calendar->timezone));
    }

    /**
     * `[today − days_behind, today + days_ahead]` in calendar-local days.
     *
     * @return array{LocalDate, LocalDate}
     */
    public static function window(OpeningHours $hours): array
    {
        $today = LocalDate::today($hours->timezone());

        return [$today->subDays(self::daysBehind()), $today->addDays(self::daysAhead())];
    }
}
