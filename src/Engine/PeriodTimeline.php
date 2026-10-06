<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Engine;

use Generator;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;

/**
 * Resolves day plans to instants and walks the coalesced opening runs forwards
 * and backwards. Holds a bounded LRU memo of day plans and their raw periods.
 *
 * Scan-edge contract: a run still open where a scan stops is yielded with the
 * edge as its end (`endsAfterScan`) or start (`startsBeforeScan`); callers must
 * never report such an edge as a real opening or closing.
 *
 * @internal
 */
final class PeriodTimeline
{
    public const int MEMO_SIZE = 512;

    /** @var array<int, array{DayPlan, list<RawPeriod>}> */
    private array $memo = [];

    public function __construct(
        public readonly WallClock $clock,
        public readonly DayResolver $resolver,
    ) {}

    public function plan(int $epochDay): DayPlan
    {
        return $this->entry($epochDay)[0];
    }

    /**
     * @return list<RawPeriod> sorted by start
     */
    public function rawPeriods(int $epochDay): array
    {
        return $this->entry($epochDay)[1];
    }

    public function memoSize(): int
    {
        return count($this->memo);
    }

    public function isOpenAt(int $instant): bool
    {
        return $this->rawPeriodAt($instant) !== null;
    }

    /**
     * The raw (uncoalesced) period containing the instant; the earliest-starting
     * one when several overlap. The next day is read too: a fall-back after local
     * midnight repeats the day before once the next day's periods have begun.
     */
    public function rawPeriodAt(int $instant): ?RawPeriod
    {
        $day = $this->clock->localEpochDay($instant);
        $found = null;

        foreach ([$day - 1, $day, $day + 1] as $candidateDay) {
            foreach ($this->rawPeriods($candidateDay) as $period) {
                if ($period->start <= $instant && $instant < $period->end && ($found === null || $period->start < $found->start)) {
                    $found = $period;
                }
            }
        }

        return $found;
    }

    /**
     * Coalesced runs with `end > $from`, in start order, scanning
     * `$limitDays` local days past the day of `$from`.
     *
     * @return Generator<int, Run>
     */
    public function forward(int $from, int $limitDays): Generator
    {
        $firstDay = $this->clock->localEpochDay($from) - 1;
        // One lookahead day beyond the scan limit decides whether the last run truly ends.
        $lastDay = $firstDay + 1 + $limitDays + 1;
        $edge = $this->clock->boundaryAt($lastDay + 1, 0);
        $current = null;

        for ($day = $firstDay; $day <= $lastDay; $day++) {
            foreach ($this->rawPeriods($day) as $period) {
                if ($current !== null && $period->start <= $current->end) {
                    $current->absorb($period);

                    continue;
                }

                if ($current !== null && $current->end > $from) {
                    yield $current;
                }

                $current = Run::from($period);
            }
        }

        if ($current !== null && $current->end > $from) {
            if ($current->end >= $edge) {
                $current->end = $edge;
                $current->endsAfterScan = true;
            }

            yield $current;
        }
    }

    /**
     * The end of a forward scan's last day: `forward()` reads one lookahead day
     * past it only to learn where a run open at this edge ends, so a run
     * starting at or after it lies outside the search window.
     */
    public function forwardEdge(int $from, int $limitDays): int
    {
        return $this->clock->boundaryAt($this->clock->localEpochDay($from) + $limitDays + 1, 0);
    }

    /**
     * Coalesced runs with `start < $from`, latest first, scanning `$limitDays`
     * local days before the day of `$from`.
     *
     * @return Generator<int, Run>
     */
    public function backward(int $from, int $limitDays): Generator
    {
        $highDay = $this->clock->localEpochDay($from);
        $lastYieldedStart = PHP_INT_MAX;
        $window = min(7, $limitDays);

        while (true) {
            $lowDay = $highDay - $window;
            $final = $window >= $limitDays;
            $lowEdge = $this->clock->boundaryAt($lowDay, 0);
            $runs = Coalescer::coalesce($this->periodsBetween($lowDay - 1, $highDay + 1));

            for ($i = count($runs) - 1; $i >= 0; $i--) {
                $run = $runs[$i];

                if ($run->start >= $from || $run->start >= $lastYieldedStart) {
                    continue;
                }

                if ($run->start >= $lowEdge) {
                    $lastYieldedStart = $run->start;

                    yield $run;

                    continue;
                }

                // The run may begin before this window: widen it, or stop at the scan edge.
                if (! $final) {
                    break;
                }

                if ($run->end > $lowEdge) {
                    $run->start = $lowEdge;
                    $run->startsBeforeScan = true;

                    yield $run;
                }

                return;
            }

            if ($final) {
                return;
            }

            $window = min($window * 2, $limitDays);
        }
    }

    /**
     * @return Generator<int, RawPeriod>
     */
    public function periodsBetween(int $fromDay, int $toDay): Generator
    {
        for ($day = $fromDay; $day <= $toDay; $day++) {
            yield from $this->rawPeriods($day);
        }
    }

    /**
     * @return array{DayPlan, list<RawPeriod>}
     */
    private function entry(int $epochDay): array
    {
        if (isset($this->memo[$epochDay])) {
            $entry = $this->memo[$epochDay];
            unset($this->memo[$epochDay]);

            return $this->memo[$epochDay] = $entry;
        }

        $plan = $this->resolver->plan(LocalDate::fromEpochDay($epochDay));
        $periods = [];

        foreach ($plan->ranges as $range) {
            $start = $this->clock->boundaryAt($epochDay, $range->start->minutes);
            $end = $range->isOvernight()
                ? $this->clock->boundaryAt($epochDay + 1, $range->end->minutes)
                : $this->clock->boundaryAt($epochDay, $range->end->minutes);

            if ($end > $start) {
                $periods[] = new RawPeriod($start, $end, $range, $plan->source, $epochDay);
            }
        }

        usort($periods, static fn (RawPeriod $a, RawPeriod $b): int => [$a->start, $a->end] <=> [$b->start, $b->end]);

        if (count($this->memo) >= self::MEMO_SIZE) {
            unset($this->memo[array_key_first($this->memo)]);
        }

        return $this->memo[$epochDay] = [$plan, $periods];
    }
}
