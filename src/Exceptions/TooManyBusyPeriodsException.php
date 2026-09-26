<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Exceptions;

/**
 * A busy-period provider returned more periods than `limits.busy_periods`.
 */
final class TooManyBusyPeriodsException extends OpeningHoursException
{
    public static function make(int $limit): self
    {
        return new self("More than {$limit} busy periods were returned for one availability evaluation.");
    }
}
