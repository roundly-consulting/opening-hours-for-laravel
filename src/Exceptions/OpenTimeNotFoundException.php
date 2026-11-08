<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Exceptions;

/**
 * `addOpenDays()` / `addOpenTime()` found no answer within `opening-hours.search_days` local
 * days after the start date: the calendar is never open, or not open enough in that window.
 */
final class OpenTimeNotFoundException extends OpeningHoursException
{
    public int $searchDays = 0;

    public static function within(int $searchDays): self
    {
        $exception = new self("The calendar has not enough open time within {$searchDays} days of the start (opening-hours.search_days).");
        $exception->searchDays = $searchDays;

        return $exception;
    }
}
