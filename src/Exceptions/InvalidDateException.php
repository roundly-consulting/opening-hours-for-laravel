<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Exceptions;

use RoundlyConsulting\OpeningHours\Enums\ViolationCode;

/**
 * A calendar date, month/day or date window is malformed.
 */
final class InvalidDateException extends OpeningHoursException
{
    public ViolationCode $violationCode = ViolationCode::InvalidDate;

    public static function invalidDate(string $value): self
    {
        return new self("Invalid date [{$value}]; expected a real Y-m-d date between 1900 and 2200.");
    }

    public static function invalidMonthDay(string $value): self
    {
        $exception = new self("Invalid month/day [{$value}]; expected m-d.");
        $exception->violationCode = ViolationCode::InvalidMonthDay;

        return $exception;
    }

    public static function inverted(string $from, string $until): self
    {
        $exception = new self("Date window [{$from}..{$until}] ends before it starts.");
        $exception->violationCode = ViolationCode::WindowInverted;

        return $exception;
    }
}
