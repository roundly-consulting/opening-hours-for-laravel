<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Exceptions;

/**
 * A busy period is malformed, or a provider yielded something that is not one.
 */
final class InvalidBusyPeriodException extends OpeningHoursException
{
    public static function emptyOrInverted(): self
    {
        return new self('A busy period must end after it starts.');
    }

    public static function invalidWeight(int $weight): self
    {
        return new self("A busy period weight must be at least 1, [{$weight}] given.");
    }

    public static function notABusyPeriod(string $type): self
    {
        return new self("A busy period provider yielded [{$type}] instead of a BusyPeriod.");
    }

    public static function invalidIdentifier(string $identifier): self
    {
        return new self("[{$identifier}] is not a valid column identifier.");
    }

    public static function invalidDuration(int $minutes): self
    {
        return new self("A default duration must be at least 1 minute, [{$minutes}] given.");
    }
}
