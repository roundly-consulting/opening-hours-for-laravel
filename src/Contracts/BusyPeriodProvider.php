<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Contracts;

use Carbon\CarbonImmutable;
use RoundlyConsulting\OpeningHours\Availability\BusyPeriod;

/**
 * Feeds availability with whatever occupies capacity. The package never knows
 * what a "booking" is — the host implements this (or uses a shipped provider).
 */
interface BusyPeriodProvider
{
    /**
     * Periods overlapping `[$start, $end)`. Returning extra is fine; the engine clips.
     *
     * @return iterable<BusyPeriod>
     */
    public function busyPeriodsBetween(CarbonImmutable $start, CarbonImmutable $end): iterable;
}
