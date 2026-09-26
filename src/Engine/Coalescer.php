<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Engine;

/**
 * Merges start-sorted raw periods into maximal runs: touching or overlapping
 * periods (`09–12` + `12–13`) become one run.
 *
 * @internal
 */
final class Coalescer
{
    /**
     * @param  iterable<RawPeriod>  $periods  sorted by start
     * @return list<Run>
     */
    public static function coalesce(iterable $periods): array
    {
        $runs = [];
        $current = null;

        foreach ($periods as $period) {
            if ($current !== null && $period->start <= $current->end) {
                $current->absorb($period);

                continue;
            }

            if ($current !== null) {
                $runs[] = $current;
            }

            $current = Run::from($period);
        }

        if ($current !== null) {
            $runs[] = $current;
        }

        return $runs;
    }
}
