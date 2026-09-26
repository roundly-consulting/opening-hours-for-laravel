<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Availability\Providers;

use Carbon\CarbonImmutable;
use RoundlyConsulting\OpeningHours\Contracts\BusyPeriodProvider;

/**
 * Every provider's busy periods together (bookings + holds + staff meetings…).
 */
final readonly class CompositeBusyPeriodProvider implements BusyPeriodProvider
{
    /** @var list<BusyPeriodProvider> */
    private array $providers;

    public function __construct(BusyPeriodProvider ...$providers)
    {
        $this->providers = array_values($providers);
    }

    public function busyPeriodsBetween(CarbonImmutable $start, CarbonImmutable $end): iterable
    {
        $periods = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->busyPeriodsBetween($start, $end) as $period) {
                $periods[] = $period;
            }
        }

        return $periods;
    }
}
