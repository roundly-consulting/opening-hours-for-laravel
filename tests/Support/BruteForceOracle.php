<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Tests\Support;

use DateTime;
use DateTimeZone;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\Engine\Compiler;
use RoundlyConsulting\OpeningHours\Engine\DayResolver;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;

/**
 * An independent reference for the boundary rule. It resolves every wall-clock
 * boundary by scanning UTC minutes for "the first minute whose local wall time
 * is at or after the boundary" — never touching `WallClock` or the transition
 * table — and derives open/closed per minute from that. Day plans (which ranges
 * apply on a date) come from the engine's resolver; only time resolution is
 * re-derived here.
 */
final class BruteForceOracle
{
    /** @var array<int, bool> grid index => open */
    private array $open = [];

    /** @var list<array{int, int}> coalesced runs in seconds */
    private array $runs = [];

    public function __construct(
        CalendarData $data,
        private readonly OracleGrid $grid,
    ) {
        $resolver = new DayResolver(Compiler::compile($data));
        $periods = [];

        for ($day = $grid->firstDay; $day <= $grid->lastDay; $day++) {
            foreach ($resolver->plan(LocalDate::fromEpochDay($day))->ranges as $range) {
                $start = $grid->boundary($day, $range->start->minutes);
                $end = $range->isOvernight()
                    ? $grid->boundary($day + 1, $range->end->minutes)
                    : $grid->boundary($day, $range->end->minutes);

                if ($start !== null && $end !== null && $end > $start) {
                    $periods[] = [$start, $end];
                }
            }
        }

        foreach ($periods as [$start, $end]) {
            for ($i = $grid->index($start); $i < $grid->index($end); $i++) {
                $this->open[$i] = true;
            }
        }

        usort($periods, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        foreach ($periods as [$start, $end]) {
            $last = count($this->runs) - 1;

            if ($last >= 0 && $start <= $this->runs[$last][1]) {
                $this->runs[$last][1] = max($this->runs[$last][1], $end);
            } else {
                $this->runs[] = [$start, $end];
            }
        }
    }

    public function isOpenAt(int $instant): bool
    {
        return isset($this->open[$this->grid->index($instant)]);
    }

    public function openSecondsBetween(int $from, int $to): int
    {
        $seconds = 0;

        for ($t = $from; $t < $to; $t += 60) {
            $seconds += $this->isOpenAt($t) ? 60 : 0;
        }

        return $seconds;
    }

    /**
     * @return array{bool, ?int} [determinable, answer]
     */
    public function nextOpen(int $t): array
    {
        foreach ($this->runs as [$start]) {
            if ($start > $t) {
                return [$start <= $this->grid->reliableUntil, $start];
            }
        }

        return [false, null];
    }

    /**
     * @return array{bool, ?int}
     */
    public function nextClose(int $t): array
    {
        foreach ($this->runs as [, $end]) {
            if ($end > $t) {
                return [$end < $this->grid->reliableUntil, $end];
            }
        }

        return [false, null];
    }

    /**
     * @return array{bool, ?int}
     */
    public function previousOpen(int $t): array
    {
        for ($i = count($this->runs) - 1; $i >= 0; $i--) {
            if ($this->runs[$i][0] < $t) {
                return [$this->runs[$i][0] > $this->grid->reliableFrom, $this->runs[$i][0]];
            }
        }

        return [false, null];
    }

    /**
     * @return array{bool, ?int}
     */
    public function previousClose(int $t): array
    {
        for ($i = count($this->runs) - 1; $i >= 0; $i--) {
            if ($this->runs[$i][1] < $t) {
                return [$this->runs[$i][1] > $this->grid->reliableFrom, $this->runs[$i][1]];
            }
        }

        return [false, null];
    }

    /**
     * Every period boundary inside the window.
     *
     * @return list<int>
     */
    public function boundariesBetween(int $from, int $to): array
    {
        $boundaries = [];

        foreach ($this->runs as [$start, $end]) {
            foreach ([$start, $end] as $instant) {
                if ($instant >= $from && $instant <= $to) {
                    $boundaries[] = $instant;
                }
            }
        }

        return $boundaries;
    }

    public static function offsetAt(DateTimeZone $zone, int $instant): int
    {
        static $probe = null;
        $probe ??= new DateTime;
        $probe->setTimestamp($instant);

        return $zone->getOffset($probe);
    }
}
