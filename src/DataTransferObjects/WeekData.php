<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\DataTransferObjects;

use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;

/**
 * A weekly template: ranges per ISO weekday. A missing weekday is closed.
 */
final readonly class WeekData
{
    /** @var array<int, list<TimeRange>> */
    public array $ranges;

    /**
     * @param  array<int, list<TimeRange>>  $ranges  keyed by ISO weekday 1..7
     */
    public function __construct(array $ranges = [])
    {
        $normalised = [];

        foreach (Weekday::ordered() as $weekday) {
            $dayRanges = $ranges[$weekday->iso()] ?? [];

            if ($dayRanges !== []) {
                $normalised[$weekday->iso()] = $dayRanges;
            }
        }

        $this->ranges = $normalised;
    }

    /**
     * @return list<TimeRange>
     */
    public function for(Weekday $day): array
    {
        return $this->ranges[$day->iso()] ?? [];
    }

    public function isEmpty(): bool
    {
        return $this->ranges === [];
    }

    /**
     * @return array<string, list<array<string, mixed>>> every weekday key, closed days as `[]`
     */
    public function toArray(bool $withMeta = true): array
    {
        $array = [];

        foreach (Weekday::ordered() as $weekday) {
            $array[$weekday->value] = array_map(
                static fn (TimeRange $range): array => $range->toArray($withMeta),
                $this->for($weekday),
            );
        }

        return $array;
    }
}
