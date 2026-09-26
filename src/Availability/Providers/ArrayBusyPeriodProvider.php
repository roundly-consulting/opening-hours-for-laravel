<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Availability\Providers;

use Carbon\CarbonImmutable;
use RoundlyConsulting\OpeningHours\Availability\BusyPeriod;
use RoundlyConsulting\OpeningHours\Contracts\BusyPeriodProvider;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidBusyPeriodException;

/**
 * A fixed list of busy periods.
 */
final readonly class ArrayBusyPeriodProvider implements BusyPeriodProvider
{
    /** @var list<BusyPeriod> */
    private array $periods;

    /**
     * @param  iterable<mixed>  $periods
     */
    public function __construct(iterable $periods)
    {
        $list = [];

        foreach ($periods as $period) {
            if (! $period instanceof BusyPeriod) {
                throw InvalidBusyPeriodException::notABusyPeriod(get_debug_type($period));
            }

            $list[] = $period;
        }

        $this->periods = $list;
    }

    public function busyPeriodsBetween(CarbonImmutable $start, CarbonImmutable $end): iterable
    {
        return array_values(array_filter(
            $this->periods,
            static fn (BusyPeriod $period): bool => $period->overlaps($start->getTimestamp(), $end->getTimestamp()),
        ));
    }
}
