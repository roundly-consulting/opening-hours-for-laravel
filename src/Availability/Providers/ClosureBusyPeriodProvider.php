<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Availability\Providers;

use Carbon\CarbonImmutable;
use Closure;
use RoundlyConsulting\OpeningHours\Availability\BusyPeriod;
use RoundlyConsulting\OpeningHours\Contracts\BusyPeriodProvider;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidBusyPeriodException;

/**
 * Busy periods from a callback `fn (CarbonImmutable $start, CarbonImmutable $end): iterable<BusyPeriod>`.
 * Anything it yields that is not a `BusyPeriod` is rejected.
 */
final readonly class ClosureBusyPeriodProvider implements BusyPeriodProvider
{
    /**
     * @param  Closure(CarbonImmutable, CarbonImmutable): iterable<mixed>  $callback
     */
    public function __construct(private Closure $callback) {}

    public function busyPeriodsBetween(CarbonImmutable $start, CarbonImmutable $end): iterable
    {
        $periods = [];

        foreach (($this->callback)($start, $end) as $period) {
            if (! $period instanceof BusyPeriod) {
                throw InvalidBusyPeriodException::notABusyPeriod(get_debug_type($period));
            }

            $periods[] = $period;
        }

        return $periods;
    }
}
