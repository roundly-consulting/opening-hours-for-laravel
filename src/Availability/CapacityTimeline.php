<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Availability;

use RoundlyConsulting\OpeningHours\Engine\PeriodTimeline;

/**
 * Two step functions over a window, merged once: open capacity (from the RAW
 * opening periods — `range.capacity ?? default`, overlapping periods take the
 * max; `$outside` where closed) and usage (sum of overlapping busy weights).
 * Each query is a binary search plus a short scan.
 *
 * @internal
 */
final class CapacityTimeline
{
    /** @var list<int> segment starts */
    private array $starts = [];

    /** @var list<int> */
    private array $capacity = [];

    /** @var list<int> */
    private array $usage = [];

    /** @var list<bool> */
    private array $open = [];

    /**
     * @param  list<BusyPeriod>  $busy
     */
    public function __construct(
        PeriodTimeline $timeline,
        array $busy,
        int $from,
        int $until,
        int $defaultCapacity,
        int $outside,
    ) {
        /** @var array<int, list<array{string, int, int}>> $events instant => [[kind, sign, value]] */
        $events = [$from => [], $until => []];

        $firstDay = $timeline->clock->localEpochDay($from) - 1;
        $lastDay = $timeline->clock->localEpochDay($until);

        foreach ($timeline->periodsBetween($firstDay, $lastDay) as $period) {
            if ($period->end <= $from || $period->start >= $until) {
                continue;
            }

            $cap = $period->range->capacity ?? $defaultCapacity;
            $events[max($period->start, $from)][] = ['cap', 1, $cap];
            $events[min($period->end, $until)][] = ['cap', -1, $cap];
        }

        foreach ($busy as $item) {
            $start = max($item->start->getTimestamp(), $from);
            $end = min($item->end->getTimestamp(), $until);

            if ($end <= $start) {
                continue;
            }

            $events[$start][] = ['use', 1, $item->weight];
            $events[$end][] = ['use', -1, $item->weight];
        }

        ksort($events);

        /** @var array<int, int> $openCaps capacity value => how many open periods carry it */
        $openCaps = [];
        $usage = 0;

        foreach ($events as $instant => $changes) {
            foreach ($changes as [$kind, $sign, $value]) {
                if ($kind === 'use') {
                    $usage += $sign * $value;
                } else {
                    $openCaps[$value] = ($openCaps[$value] ?? 0) + $sign;

                    if ($openCaps[$value] === 0) {
                        unset($openCaps[$value]);
                    }
                }
            }

            $this->starts[] = $instant;
            $this->open[] = $openCaps !== [];
            $this->capacity[] = $openCaps === [] ? $outside : max(array_keys($openCaps));
            $this->usage[] = $usage;
        }
    }

    /**
     * The smallest `capacity − usage` over `[$from, $until)`.
     */
    public function minRemaining(int $from, int $until): int
    {
        $min = PHP_INT_MAX;

        for ($i = $this->segmentAt($from), $n = count($this->starts); $i < $n && $this->starts[$i] < $until; $i++) {
            $min = min($min, $this->capacity[$i] - $this->usage[$i]);
        }

        return $min === PHP_INT_MAX ? 0 : $min;
    }

    /**
     * Maximal open stretches where at least `$weight` units are free.
     *
     * @return list<array{int, int}>
     */
    public function freeStretches(int $weight): array
    {
        $stretches = [];
        $count = count($this->starts);

        for ($i = 0; $i < $count - 1; $i++) {
            if (! $this->open[$i] || $weight > $this->capacity[$i] - $this->usage[$i]) {
                continue;
            }

            [$start, $end] = [$this->starts[$i], $this->starts[$i + 1]];
            $last = count($stretches) - 1;

            if ($last >= 0 && $stretches[$last][1] === $start) {
                $stretches[$last][1] = $end;
            } else {
                $stretches[] = [$start, $end];
            }
        }

        return $stretches;
    }

    private function segmentAt(int $instant): int
    {
        $low = 0;
        $high = count($this->starts) - 1;

        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);

            if ($this->starts[$mid] <= $instant) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $low;
    }
}
