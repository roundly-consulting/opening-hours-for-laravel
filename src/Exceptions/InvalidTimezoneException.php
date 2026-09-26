<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Exceptions;

/**
 * A timezone is not a known IANA identifier (fixed offsets such as `+02:00`
 * are rejected on purpose — they have no DST rules).
 */
final class InvalidTimezoneException extends OpeningHoursException
{
    public static function unknown(string $timezone): self
    {
        return new self("Timezone [{$timezone}] is not a valid IANA timezone identifier.");
    }
}
