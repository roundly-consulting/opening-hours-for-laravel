<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Exceptions;

use RoundlyConsulting\OpeningHours\Enums\ViolationCode;

/**
 * A wall-clock time or time range is malformed or out of range.
 */
final class InvalidTimeException extends OpeningHoursException
{
    public ViolationCode $violationCode = ViolationCode::InvalidTime;

    public static function invalidFormat(string $value): self
    {
        return new self("Invalid time [{$value}]; expected HH:MM between 00:00 and 24:00.");
    }

    public static function outOfRange(int $minutes): self
    {
        return new self("Time of [{$minutes}] minutes is outside 0..1440.");
    }

    public static function invalidRange(string $value): self
    {
        return new self("Invalid time range [{$value}]; expected HH:MM-HH:MM.");
    }

    public static function emptyRange(string $value): self
    {
        $exception = new self("Time range [{$value}] is empty (start equals end).");
        $exception->violationCode = ViolationCode::EmptyRange;

        return $exception;
    }

    public static function startAt24(string $value): self
    {
        $exception = new self("Time range [{$value}] cannot start at 24:00.");
        $exception->violationCode = ViolationCode::StartAt24;

        return $exception;
    }

    public static function invalidCapacity(int $capacity): self
    {
        $exception = new self("Capacity [{$capacity}] must be between 1 and 1000.");
        $exception->violationCode = ViolationCode::InvalidCapacity;

        return $exception;
    }

    public static function invalidWeekday(int $weekday): self
    {
        $exception = new self("Weekday [{$weekday}] must be an ISO weekday between 1 and 7.");
        $exception->violationCode = ViolationCode::UnknownWeekday;

        return $exception;
    }
}
