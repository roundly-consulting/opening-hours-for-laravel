<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Exceptions;

/**
 * A write targeted a calendar the owner does not have (never synced, or
 * soft-deleted). Create it with `sync()` first.
 */
final class CalendarNotFoundException extends OpeningHoursException
{
    public static function forKey(string $key): self
    {
        return new self("The owner has no live [{$key}] opening-hours calendar; sync() one first.");
    }
}
