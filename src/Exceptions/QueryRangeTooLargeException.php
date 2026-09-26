<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Exceptions;

/**
 * A span query covers more days than `opening-hours.max_query_days` allows.
 */
final class QueryRangeTooLargeException extends OpeningHoursException
{
    public int $requestedDays = 0;

    public int $maxDays = 0;

    public static function make(int $requestedDays, int $maxDays): self
    {
        $exception = new self("The query spans {$requestedDays} days; at most {$maxDays} are allowed.");
        $exception->requestedDays = $requestedDays;
        $exception->maxDays = $maxDays;

        return $exception;
    }
}
