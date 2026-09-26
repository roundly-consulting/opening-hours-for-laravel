<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Tests\Support;

use Random\Engine\Mt19937;
use Random\Randomizer;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ScheduleData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\WeekData;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;
use RoundlyConsulting\OpeningHours\ValueObjects\YearlyWindow;

/**
 * Deterministic random calendars for the oracle: 1–3 schedules, 0–12 exceptions,
 * overnight / 24 h / DST-hour ranges, all placed relative to a focus date so they
 * actually exercise the transition under test. Built as DTOs (no validation), so
 * overlapping ranges are allowed — the engine must union them at runtime.
 */
final class CalendarGenerator
{
    /** @var list<array<string, mixed>> */
    private array $shapes = [];

    public function __construct(int $count, int $seed = 20260926)
    {
        $random = new Randomizer(new Mt19937($seed));

        for ($i = 0; $i < $count; $i++) {
            $this->shapes[] = $this->shape($random);
        }
    }

    public function count(): int
    {
        return count($this->shapes);
    }

    public function calendar(int $index, LocalDate $focus): CalendarData
    {
        $shape = $this->shapes[$index];
        $schedules = [];

        foreach ($shape['schedules'] as $schedule) {
            $window = null;

            if ($schedule['window'] === 'absolute') {
                $window = new AbsoluteWindow($focus->addDays($schedule['from']), $focus->addDays($schedule['until']));
            } elseif ($schedule['window'] === 'yearly') {
                $window = new YearlyWindow($focus->addDays($schedule['from'])->monthDay(), $focus->addDays($schedule['until'])->monthDay());
            }

            $schedules[] = new ScheduleData(new WeekData($schedule['week']), $window, $schedule['priority']);
        }

        $exceptions = [];

        foreach ($shape['exceptions'] as $exception) {
            $from = $focus->addDays($exception['offset']);
            $until = $from->addDays($exception['span']);
            $window = $exception['yearly']
                ? new YearlyWindow($from->monthDay(), $until->monthDay())
                : new AbsoluteWindow($from, $until);

            $exceptions[] = new ExceptionData($window, $exception['ranges']);
        }

        return new CalendarData(schedules: $schedules, exceptions: $exceptions);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(Randomizer $random): array
    {
        $schedules = [['window' => null, 'priority' => 0, 'week' => $this->week($random)]];

        for ($s = $random->getInt(0, 2); $s > 0; $s--) {
            $from = $random->getInt(-3, 1);
            $schedules[] = [
                'window' => $random->getInt(0, 1) === 0 ? 'absolute' : 'yearly',
                'priority' => $random->getInt(-2, 5),
                'from' => $from,
                'until' => $from + $random->getInt(0, 4),
                'week' => $this->week($random),
            ];
        }

        $exceptions = [];

        for ($e = $random->getInt(0, 12); $e > 0; $e--) {
            $exceptions[] = [
                'offset' => $random->getInt(-3, 2),
                'span' => $random->getInt(0, 2),
                'yearly' => $random->getInt(0, 3) === 0,
                'ranges' => $random->getInt(0, 2) === 0 ? [] : $this->ranges($random, $random->getInt(1, 2)),
            ];
        }

        return ['schedules' => $schedules, 'exceptions' => $exceptions];
    }

    /**
     * @return array<int, list<TimeRange>>
     */
    private function week(Randomizer $random): array
    {
        $week = [];

        for ($iso = 1; $iso <= 7; $iso++) {
            $count = $random->getInt(0, 3);

            if ($count > 0) {
                $week[$iso] = $this->ranges($random, $count);
            }
        }

        return $week;
    }

    /**
     * @return list<TimeRange>
     */
    private function ranges(Randomizer $random, int $count): array
    {
        $ranges = [];

        for ($r = 0; $r < $count; $r++) {
            $ranges[] = match ($random->getInt(0, 5)) {
                0 => TimeRange::allDay(),
                // Starts in the small hours, where transitions live.
                1, 2 => $this->range($random->getInt(0, 4 * 60), $random->getInt(1, 5 * 60)),
                // Overnight.
                3 => $this->range($random->getInt(18 * 60, 1439), $random->getInt(6 * 60, 12 * 60)),
                default => $this->range($random->getInt(0, 1439), $random->getInt(1, 1439)),
            };
        }

        return $ranges;
    }

    private function range(int $start, int $length): TimeRange
    {
        $end = ($start + $length) % 1440;

        if ($end === $start) {
            $end = ($end + 1) % 1440;
        }

        return TimeRange::fromMinutes($start, $end === 0 ? 1440 : $end);
    }
}
