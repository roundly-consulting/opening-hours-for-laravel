<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Availability;

use RoundlyConsulting\OpeningHours\Engine\WallClock;

/**
 * Rounds an instant up to the next local wall-clock grid line
 * (`anchor + k · grid` minutes past midnight). Bounded to four passes, which
 * converges across DST gaps and 30-minute shifts.
 *
 * @internal
 */
final class SlotGrid
{
    public static function ceil(int $instant, int $grid, int $anchor, WallClock $clock): int
    {
        for ($pass = 0; $pass < 4; $pass++) {
            $minute = $clock->localMinute($instant);
            $remainder = (($minute - $anchor) % $grid + $grid) % $grid;
            $seconds = (($instant % 60) + 60) % 60;

            if ($remainder === 0 && $seconds === 0) {
                return $instant;
            }

            $instant = $instant - $seconds + ($grid - $remainder) * 60;
        }

        return $instant;
    }
}
