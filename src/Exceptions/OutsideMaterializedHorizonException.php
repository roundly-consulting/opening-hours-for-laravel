<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Exceptions;

/**
 * A materialized-interval scope was asked about an instant outside the
 * materialized window, where it would silently answer "closed".
 */
final class OutsideMaterializedHorizonException extends OpeningHoursException
{
    public static function make(string $instant, string $from, string $until): self
    {
        return new self("[{$instant}] is outside the materialized horizon [{$from} .. {$until}].");
    }
}
