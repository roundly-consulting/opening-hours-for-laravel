<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Engine;

use RoundlyConsulting\OpeningHours\DataTransferObjects\WeekData;
use RoundlyConsulting\OpeningHours\Enums\Weekday;

/**
 * A weekly template mapped onto the circular 10 080-minute week: overnight
 * ranges run into the next day, Sunday wraps into Monday.
 *
 * @internal
 */
final class WeekCoverage
{
    public const int WEEK_MINUTES = 10080;

    /**
     * Linear segments `[start, end)` inside `[0, 10080)`, tagged with the defining
     * weekday and the range's index on that day. A wrapping range yields two.
     *
     * @return list<array{int, int, Weekday, int}>
     */
    public static function segments(WeekData $week): array
    {
        $segments = [];

        foreach (Weekday::ordered() as $weekday) {
            foreach ($week->for($weekday) as $index => $range) {
                $start = ($weekday->iso() - 1) * 1440 + $range->start->minutes;
                $end = $start + $range->durationMinutes();

                if ($end > self::WEEK_MINUTES) {
                    $segments[] = [$start, self::WEEK_MINUTES, $weekday, $index];
                    $segments[] = [0, $end - self::WEEK_MINUTES, $weekday, $index];
                } else {
                    $segments[] = [$start, $end, $weekday, $index];
                }
            }
        }

        return $segments;
    }

    public static function coveredMinutes(WeekData $week): int
    {
        $segments = self::segments($week);
        usort($segments, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $covered = 0;
        $runStart = null;
        $runEnd = null;

        foreach ($segments as [$start, $end]) {
            if ($runEnd === null || $start > $runEnd) {
                if ($runEnd !== null) {
                    $covered += $runEnd - $runStart;
                }

                $runStart = $start;
                $runEnd = $end;
            } else {
                $runEnd = max($runEnd, $end);
            }
        }

        if ($runEnd !== null) {
            $covered += $runEnd - $runStart;
        }

        return $covered;
    }

    public static function coversWholeWeek(WeekData $week): bool
    {
        return self::coveredMinutes($week) >= self::WEEK_MINUTES;
    }

    /**
     * Every pair of ranges that overlap (touching is fine), as
     * `[[weekdayA, indexA], [weekdayB, indexB]]`, each pair once.
     *
     * @return list<array{array{Weekday, int}, array{Weekday, int}}>
     */
    public static function overlaps(WeekData $week): array
    {
        $segments = self::segments($week);
        $pairs = [];
        $seen = [];
        $count = count($segments);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                [$aStart, $aEnd, $aDay, $aIndex] = $segments[$i];
                [$bStart, $bEnd, $bDay, $bIndex] = $segments[$j];

                if ($aDay === $bDay && $aIndex === $bIndex) {
                    continue;
                }

                if ($aStart < $bEnd && $bStart < $aEnd) {
                    $first = [$aDay, $aIndex];
                    $second = [$bDay, $bIndex];

                    if ([$bDay->iso(), $bIndex] < [$aDay->iso(), $aIndex]) {
                        [$first, $second] = [$second, $first];
                    }

                    $key = $first[0]->iso().':'.$first[1].'|'.$second[0]->iso().':'.$second[1];

                    if (! isset($seen[$key])) {
                        $seen[$key] = true;
                        $pairs[] = [$first, $second];
                    }
                }
            }
        }

        return $pairs;
    }
}
