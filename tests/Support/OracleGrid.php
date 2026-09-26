<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Tests\Support;

use DateTimeZone;

/**
 * A minute grid of UTC instants around a transition, each tagged with its local
 * wall minute. `boundary()` answers "first instant whose wall time is at or after
 * (day, minute)" by search over the running maximum — no transition table involved.
 */
final class OracleGrid
{
    /** @var list<int> */
    private array $instants = [];

    /** @var list<int> running max of wall minutes */
    private array $prefixMax = [];

    public readonly int $firstDay;

    public readonly int $lastDay;

    public readonly int $reliableFrom;

    public readonly int $reliableUntil;

    public function __construct(
        public readonly DateTimeZone $zone,
        public readonly int $windowStart,
        public readonly int $windowEnd,
    ) {
        $gridStart = $windowStart - 5 * 86400;
        $gridEnd = $windowEnd + 5 * 86400;
        $max = PHP_INT_MIN;

        for ($t = $gridStart; $t <= $gridEnd; $t += 60) {
            $wall = intdiv($t + BruteForceOracle::offsetAt($zone, $t), 60);
            $max = max($max, $wall);
            $this->instants[] = $t;
            $this->prefixMax[] = $max;
        }

        $this->firstDay = intdiv($windowStart + BruteForceOracle::offsetAt($zone, $windowStart), 86400) - 2;
        $this->lastDay = intdiv($windowEnd + BruteForceOracle::offsetAt($zone, $windowEnd), 86400) + 2;
        $this->reliableFrom = $windowStart - 86400;
        $this->reliableUntil = $windowEnd + 86400;
    }

    public function boundary(int $epochDay, int $minute): ?int
    {
        $target = $epochDay * 1440 + $minute;
        $low = 0;
        $high = count($this->prefixMax) - 1;

        if ($this->prefixMax[$high] < $target || $this->prefixMax[0] >= $target) {
            return null;
        }

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);

            if ($this->prefixMax[$mid] >= $target) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }

        return $this->instants[$low];
    }

    public function index(int $instant): int
    {
        return intdiv($instant - $this->instants[0], 60);
    }
}
