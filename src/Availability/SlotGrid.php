<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Availability;

use RoundlyConsulting\OpeningHours\Engine\WallClock;
use RoundlyConsulting\OpeningHours\ValueObjects\Time;

/**
 * Rounds an instant up to the first instant at or after it at which the local
 * wall clock reads a grid line (`anchor + k · grid` minutes past midnight); past
 * a day's last line it lands on the next day's first one. The slot generator
 * rounds a run start (so only a run start restarts the grid at local midnight)
 * and the previous start plus the step, a minimum gap that may pass the next
 * day's first line. A jump never crosses a timezone transition: the wall clock
 * is re-read there, so a DST gap or a repeated fall-back hour moves no line.
 * Bounded passes converge across DST gaps, 30-minute shifts and the midnight
 * restart.
 *
 * @internal
 */
final class SlotGrid
{
    private const int PASSES = 12;

    public static function ceil(int $instant, int $grid, int $anchor, WallClock $clock): int
    {
        $first = $anchor % $grid;

        // Every line of the day lies past midnight: there is no grid line on any day.
        if ($first >= Time::END_OF_DAY) {
            return PHP_INT_MAX;
        }

        for ($pass = 0; $pass < self::PASSES; $pass++) {
            $minute = $clock->localMinute($instant);
            $remainder = (($minute - $first) % $grid + $grid) % $grid;
            $seconds = (($instant % 60) + 60) % 60;

            if ($remainder === 0 && $seconds === 0) {
                return $instant;
            }

            $step = $grid - $remainder;
            $next = $instant - $seconds + $step * 60;

            if ($minute + $step >= Time::END_OF_DAY) {
                // The day has no further line: the grid restarts at the next local midnight
                // (unless a fall-back hour repeats wall times, and so a line, before it).
                $next = min($next, $clock->boundaryAt($clock->localEpochDay($instant) + 1, 0));
            }

            // Wall minutes advance one per real minute only until the next transition.
            $transition = $clock->nextTransitionAfter($instant);

            if ($transition !== null && $transition < $next) {
                $next = $transition;
            }

            $instant = $next;
        }

        return $instant;
    }
}
