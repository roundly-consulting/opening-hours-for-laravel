<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Engine;

use RoundlyConsulting\OpeningHours\Enums\DaySource;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;

/**
 * One defined range resolved to instants (Unix seconds), before coalescing.
 *
 * @internal
 */
final readonly class RawPeriod
{
    public function __construct(
        public int $start,
        public int $end,
        public TimeRange $range,
        public DaySource $source,
        public int $epochDay,
    ) {}
}
