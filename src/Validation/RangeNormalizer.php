<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Validation;

use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ScheduleData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\WeekData;
use RoundlyConsulting\OpeningHours\Engine\WeekCoverage;
use RoundlyConsulting\OpeningHours\ValueObjects\Time;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;

/**
 * Unions overlapping ranges instead of rejecting them (imported data often
 * overlaps). Weekly ranges merge on the circular week; a union longer than a
 * day is split into touching day-sized ranges. A range that overlaps nothing
 * is kept as it was; a merged range keeps a label, capacity or meta entry only
 * when every part agrees on it.
 */
final class RangeNormalizer
{
    public static function normalize(CalendarData $data): CalendarData
    {
        return new CalendarData(
            $data->timezone,
            $data->label,
            array_map(static fn (ScheduleData $schedule): ScheduleData => new ScheduleData(
                self::week($schedule->week),
                $schedule->window,
                $schedule->priority,
                $schedule->label,
                $schedule->meta,
                $schedule->id,
            ), $data->schedules),
            array_map(static fn (ExceptionData $exception): ExceptionData => new ExceptionData(
                $exception->window,
                self::day($exception->ranges),
                $exception->label,
                $exception->meta,
                $exception->id,
            ), $data->exceptions),
            $data->meta,
            $data->revision,
        );
    }

    public static function week(WeekData $week): WeekData
    {
        /** @var list<array{int, int, list<TimeRange>}> $segments */
        $segments = [];

        foreach ($week->ranges as $iso => $ranges) {
            foreach ($ranges as $range) {
                $start = ($iso - 1) * 1440 + $range->start->minutes;
                $segments[] = [$start, $start + $range->durationMinutes(), [$range]];
            }
        }

        if ($segments === []) {
            return $week;
        }

        usort($segments, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($segments as $segment) {
            $last = count($merged) - 1;

            if ($last >= 0 && $segment[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $segment[1]);
                $merged[$last][2] = [...$merged[$last][2], ...$segment[2]];
            } else {
                $merged[] = $segment;
            }
        }

        // Circular wrap: the last run may reach past Sunday midnight into the first.
        $last = count($merged) - 1;

        while ($last > 0 && $merged[$last][1] - WeekCoverage::WEEK_MINUTES >= $merged[0][0]) {
            $merged[$last][1] = max($merged[$last][1], $merged[0][1] + WeekCoverage::WEEK_MINUTES);
            $merged[$last][2] = [...$merged[$last][2], ...$merged[0][2]];
            array_shift($merged);
            $last--;
        }

        $result = [];

        foreach ($merged as [$start, $end, $parts]) {
            if ($end - $start >= WeekCoverage::WEEK_MINUTES) {
                for ($iso = 1; $iso <= 7; $iso++) {
                    $result[$iso] = [new TimeRange(new Time(0), new Time(Time::END_OF_DAY), ...self::shared($parts))];
                }

                return new WeekData($result);
            }

            foreach (self::split($start, $end) as [$chunkStart, $chunkEnd]) {
                $iso = intdiv($chunkStart, 1440) % 7 + 1;
                $startMinute = $chunkStart % 1440;
                $endMinute = ($chunkEnd - intdiv($chunkStart, 1440) * 1440) % 1440;
                $endMinute = $chunkEnd - intdiv($chunkStart, 1440) * 1440 === 1440 ? Time::END_OF_DAY : $endMinute;
                $result[$iso][] = new TimeRange(new Time($startMinute), new Time($endMinute), ...self::shared($parts));
            }
        }

        foreach ($result as $iso => $ranges) {
            usort($ranges, static fn (TimeRange $a, TimeRange $b): int => $a->start->minutes <=> $b->start->minutes);
            $result[$iso] = $ranges;
        }

        return new WeekData($result);
    }

    /**
     * One date's ranges merged on a linear (non-wrapping) timeline. A union
     * longer than a day cannot be one range on one date; those parts are left
     * as they were so validation reports the overlap.
     *
     * @param  list<TimeRange>  $ranges
     * @return list<TimeRange>
     */
    public static function day(array $ranges): array
    {
        $segments = array_map(static fn (TimeRange $range): array => [$range->start->minutes, $range->endOffsetMinutes(), [$range]], $ranges);
        usort($segments, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($segments as $segment) {
            $last = count($merged) - 1;

            if ($last >= 0 && $segment[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $segment[1]);
                $merged[$last][2] = [...$merged[$last][2], ...$segment[2]];
            } else {
                $merged[] = $segment;
            }
        }

        $result = [];

        foreach ($merged as [$start, $end, $parts]) {
            if ($end - $start > 1440 || ($end - $start === 1440 && $start !== 0)) {
                array_push($result, ...$parts);

                continue;
            }

            $result[] = new TimeRange(new Time($start), new Time($end > 1440 ? $end - 1440 : $end), ...self::shared($parts));
        }

        return $result;
    }

    /**
     * Split `[start, end)` (week minutes, may exceed the week) into ranges of at
     * most a day that each start on their own day where needed.
     *
     * @return list<array{int, int}>
     */
    private static function split(int $start, int $end): array
    {
        if ($end - $start < 1440 || ($end - $start === 1440 && $start % 1440 === 0)) {
            return [[self::wrap($start), self::wrap($start) + ($end - $start)]];
        }

        $chunks = [];
        $cursor = $start;

        while ($cursor < $end) {
            $dayEnd = (intdiv($cursor, 1440) + 1) * 1440;
            $chunkEnd = min($dayEnd, $end);
            $chunks[] = [self::wrap($cursor), self::wrap($cursor) + ($chunkEnd - $cursor)];
            $cursor = $chunkEnd;
        }

        return $chunks;
    }

    private static function wrap(int $minute): int
    {
        return $minute % WeekCoverage::WEEK_MINUTES;
    }

    /**
     * @param  list<TimeRange>  $parts
     * @return array{label: ?string, capacity: ?int, meta: array<string, mixed>|null}
     */
    private static function shared(array $parts): array
    {
        $labels = array_unique(array_map(static fn (TimeRange $range): string => (string) $range->label, $parts));
        $capacities = array_unique(array_map(static fn (TimeRange $range): string => (string) $range->capacity, $parts));

        return [
            'label' => count($labels) === 1 ? $parts[0]->label : null,
            'capacity' => count($capacities) === 1 ? $parts[0]->capacity : null,
            'meta' => count($parts) === 1 ? $parts[0]->meta : self::sharedMeta($parts),
        ];
    }

    /**
     * Only entries with the same value in every part — a merged range must not
     * claim, say, a room that applied to just one of the ranges it absorbed.
     *
     * @param  list<TimeRange>  $parts
     * @return array<string, mixed>|null
     */
    private static function sharedMeta(array $parts): ?array
    {
        $meta = $parts[0]->meta ?? [];

        foreach ($parts as $part) {
            $other = $part->meta ?? [];
            $meta = array_filter(
                $meta,
                static fn (mixed $value, int|string $key): bool => array_key_exists($key, $other) && $other[$key] === $value,
                ARRAY_FILTER_USE_BOTH,
            );
        }

        return $meta === [] ? null : $meta;
    }
}
