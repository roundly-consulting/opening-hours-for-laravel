<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Availability;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidBusyPeriodException;

/**
 * Something that occupies capacity during `[start, end)` — a booking, a hold,
 * a meeting. `weight` is how many capacity units it takes.
 */
final readonly class BusyPeriod
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public int $weight = 1,
        public ?string $reference = null,
    ) {
        if ($end->getTimestamp() <= $start->getTimestamp()) {
            throw InvalidBusyPeriodException::emptyOrInverted();
        }

        if ($weight < 1) {
            throw InvalidBusyPeriodException::invalidWeight($weight);
        }
    }

    public static function make(DateTimeInterface $start, DateTimeInterface $end, int $weight = 1, ?string $reference = null): self
    {
        return new self(CarbonImmutable::instance($start), CarbonImmutable::instance($end), $weight, $reference);
    }

    public function overlaps(int $start, int $end): bool
    {
        return $this->start->getTimestamp() < $end && $this->end->getTimestamp() > $start;
    }
}
