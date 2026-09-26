<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Exceptions;

/**
 * An optimistic-concurrency write expected a revision the calendar no longer
 * has — somebody else wrote in between. Nothing was changed.
 */
final class StaleOpeningHoursException extends OpeningHoursException
{
    public int $expected = 0;

    public int $actual = 0;

    public static function make(int $expected, int $actual): self
    {
        $exception = new self("The opening hours changed concurrently: expected revision {$expected}, found {$actual}.");
        $exception->expected = $expected;
        $exception->actual = $actual;

        return $exception;
    }
}
