<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Validation;

/**
 * How lenient parsing is: merge overlapping ranges instead of rejecting them,
 * and whether a timezone must be given.
 */
final readonly class ParseOptions
{
    public function __construct(
        public bool $mergeOverlapping = false,
        public bool $requireTimezone = false,
    ) {}
}
