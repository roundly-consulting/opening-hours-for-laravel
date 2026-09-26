<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Availability;

use RoundlyConsulting\OpeningHours\Engine\WallClock;
use RoundlyConsulting\OpeningHours\ValueObjects\Time;

/**
 * Rounds an instant up to the next local wall-clock grid line
 * (`anchor + k · grid` minutes past midnight). The grid restarts at every local
 * midnight, so a grid that does not divide the day (or is longer than it) never
 * skips the next day's first line. Bounded to six passes, which converges across
 * DST gaps, 30-minute shifts and the midnight restart.
 *
 * @internal
 */
final class SlotGrid
{
    public static function ceil(int $instant, int $grid, int $anchor, WallClock $clock): int
    {
        $first = $anchor % $grid;

        // Every line of the day lies past midnight: there is no grid line on any day.
        if ($first >= Time::END_OF_DAY) {
            return PHP_INT_MAX;
        }

        for ($pass = 0; $pass < 6; $pass++) {
            $minute = $clock->localMinute($instant);
            $remainder = (($minute - $first) % $grid + $grid) % $grid;
            $seconds = (($instant % 60) + 60) % 60;

            if ($remainder === 0 && $seconds === 0) {
                return $instant;
            }

            $step = $grid - $remainder;

            if ($minute + $step >= Time::END_OF_DAY) {
                // The day has no further line: continue from the next local midnight.
                $instant = $clock->boundaryAt($clock->localEpochDay($instant) + 1, 0);

                continue;
            }

            $instant = $instant - $seconds + $step * 60;
        }

        return $instant;
    }
}
