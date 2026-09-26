<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Availability\Providers;

use Carbon\CarbonImmutable;
use RoundlyConsulting\OpeningHours\Contracts\BusyPeriodProvider;

/**
 * Nothing is ever busy.
 */
final class NullBusyPeriodProvider implements BusyPeriodProvider
{
    public function busyPeriodsBetween(CarbonImmutable $start, CarbonImmutable $end): iterable
    {
        return [];
    }
}
