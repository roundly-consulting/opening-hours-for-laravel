<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Exceptions;

/**
 * A slot query or availability setting is out of range.
 */
final class InvalidSlotQueryException extends OpeningHoursException
{
    public static function missingDuration(): self
    {
        return new self('A slot query needs a duration; call duration(minutes) first.');
    }

    public static function outOfRange(string $setting, int $value, int $min, ?int $max = null): self
    {
        $bound = $max === null ? "at least {$min}" : "between {$min} and {$max}";

        return new self("[{$setting}] must be {$bound}, [{$value}] given.");
    }
}
