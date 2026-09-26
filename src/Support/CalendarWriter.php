<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Support;

use Closure;
use RoundlyConsulting\OpeningHours\Contracts\DateWindow;
use RoundlyConsulting\OpeningHours\Enums\Recurrence;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\ExceptionRange;
use RoundlyConsulting\OpeningHours\Models\ScheduleRange;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;
use RoundlyConsulting\OpeningHours\ValueObjects\YearlyWindow;

/**
 * Shared persistence helpers for the write actions: transactions on the
 * calendar model's own connection, a locked re-read, window ⇄ column mapping
 * and bulk range inserts.
 *
 * @internal
 */
final class CalendarWriter
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function transaction(Closure $callback): mixed
    {
        return CalendarModel::make()->getConnection()->transaction($callback);
    }

    public static function lock(int $calendarId): Calendar
    {
        $class = CalendarModel::class();

        return $class::query()->withTrashed()->whereKey($calendarId)->lockForUpdate()->firstOrFail();
    }

    /**
     * A window as `[recurrence, from, until]` columns; yearly bounds use the
     * canonical leap year 2000.
     *
     * @return array{Recurrence, ?LocalDate, ?LocalDate}
     */
    public static function windowColumns(?DateWindow $window): array
    {
        return match (true) {
            $window instanceof YearlyWindow => [Recurrence::Yearly, $window->from->toDateIn(2000), $window->until->toDateIn(2000, asEnd: true)],
            $window instanceof AbsoluteWindow => [Recurrence::None, $window->from, $window->until],
            default => [Recurrence::None, null, null],
        };
    }

    /**
     * @param  array<int, list<TimeRange>>  $rangesByWeekday
     */
    public static function insertScheduleRanges(int $scheduleId, array $rangesByWeekday): void
    {
        $rows = [];

        foreach ($rangesByWeekday as $iso => $ranges) {
            foreach ($ranges as $position => $range) {
                $rows[] = ['schedule_id' => $scheduleId, 'weekday' => $iso, ...self::rangeRow($range, $position)];
            }
        }

        if ($rows !== []) {
            ScheduleRange::query()->insert($rows);
        }
    }

    /**
     * @param  list<TimeRange>  $ranges
     */
    public static function insertExceptionRanges(int $ruleId, array $ranges): void
    {
        $rows = [];

        foreach ($ranges as $position => $range) {
            $rows[] = ['exception_rule_id' => $ruleId, ...self::rangeRow($range, $position)];
        }

        if ($rows !== []) {
            ExceptionRange::query()->insert($rows);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function rangeRow(TimeRange $range, int $position): array
    {
        $now = Clock::now();

        return [
            'start_minute' => $range->start->minutes,
            'end_minute' => $range->end->minutes,
            'capacity' => $range->capacity,
            'label' => $range->label,
            'meta' => $range->meta === null ? null : json_encode($range->meta, JSON_THROW_ON_ERROR),
            'position' => $position,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
