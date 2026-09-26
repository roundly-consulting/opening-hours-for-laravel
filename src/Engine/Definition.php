<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Engine;

use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ScheduleData;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\OpeningHours\ValueObjects\YearlyWindow;

/**
 * A compiled calendar: schedules in precedence order and lazily built date
 * indexes for exceptions. Pure — it knows nothing about the database.
 *
 * @internal
 */
final class Definition
{
    /**
     * Bumped whenever the cached payload shape changes, so old cache entries
     * are never read by new code.
     */
    public const string FORMAT = '1';

    /**
     * One-off exceptions up to this many days are expanded into a per-date index;
     * longer ones are scanned. Expanding every long span would let one calendar
     * allocate hundreds of thousands of keys.
     */
    public const int EXPAND_MAX_SPAN = 31;

    /** @var array<int, ExceptionData>|null epoch day => narrowest one-off exception */
    private ?array $shortOneOff = null;

    /** @var list<array{ExceptionData, int, int, int}>|null [exception, from epoch day, until epoch day, span] */
    private ?array $longOneOff = null;

    /** @var array<int, array<int, ExceptionData>> leap flag => [month*100+day => exception] */
    private array $yearly = [];

    /**
     * @param  list<ScheduleData>  $schedules  in precedence order
     * @param  list<ExceptionData>  $oneOff
     * @param  list<ExceptionData>  $yearlyExceptions
     */
    public function __construct(
        public readonly CalendarData $data,
        public readonly array $schedules,
        private readonly array $oneOff,
        private readonly array $yearlyExceptions,
    ) {}

    public function hasExceptions(): bool
    {
        return $this->oneOff !== [] || $this->yearlyExceptions !== [];
    }

    public function scheduleFor(LocalDate $date): ?ScheduleData
    {
        foreach ($this->schedules as $schedule) {
            if ($schedule->isBase() || $schedule->window?->contains($date) === true) {
                return $schedule;
            }
        }

        return null;
    }

    public function oneOffExceptionFor(LocalDate $date): ?ExceptionData
    {
        if ($this->oneOff === []) {
            return null;
        }

        $this->buildOneOffIndex();
        $day = $date->toEpochDay();

        if (isset($this->shortOneOff[$day])) {
            return $this->shortOneOff[$day];
        }

        $best = null;
        $bestSpan = PHP_INT_MAX;

        foreach ($this->longOneOff ?? [] as [$exception, $from, $until, $span]) {
            if ($from > $day) {
                break;
            }

            if ($day <= $until && $span < $bestSpan) {
                $best = $exception;
                $bestSpan = $span;
            }
        }

        return $best;
    }

    public function yearlyExceptionFor(LocalDate $date): ?ExceptionData
    {
        if ($this->yearlyExceptions === []) {
            return null;
        }

        $leap = $date->isLeapYear() ? 1 : 0;

        if (! isset($this->yearly[$leap])) {
            $this->yearly[$leap] = $this->buildYearlyIndex($leap === 1 ? 2000 : 2001);
        }

        return $this->yearly[$leap][$date->month * 100 + $date->day] ?? null;
    }

    private function buildOneOffIndex(): void
    {
        if ($this->shortOneOff !== null) {
            return;
        }

        $short = [];
        $spans = [];
        $long = [];

        foreach ($this->oneOff as $exception) {
            $window = $exception->window;

            if (! $window instanceof AbsoluteWindow || $window->from === null || $window->until === null) {
                continue;
            }

            $from = $window->from->toEpochDay();
            $until = $window->until->toEpochDay();
            $span = $until - $from + 1;

            if ($span > self::EXPAND_MAX_SPAN) {
                $long[] = [$exception, $from, $until, $span];

                continue;
            }

            for ($day = $from; $day <= $until; $day++) {
                if (! isset($short[$day]) || $span < $spans[$day]) {
                    $short[$day] = $exception;
                    $spans[$day] = $span;
                }
            }
        }

        usort($long, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        $this->shortOneOff = $short;
        $this->longOneOff = $long;
    }

    /**
     * @return array<int, ExceptionData>
     */
    private function buildYearlyIndex(int $year): array
    {
        $index = [];
        $spans = [];

        foreach ($this->yearlyExceptions as $exception) {
            $window = $exception->window;

            if (! $window instanceof YearlyWindow) {
                continue;
            }

            $span = $window->spanDays();
            $start = $window->from->toDateIn($year)->toEpochDay();
            $end = $window->until->toDateIn($year, asEnd: true)->toEpochDay();
            $segments = $window->wraps()
                ? [[$start, (new LocalDate($year, 12, 31))->toEpochDay()], [(new LocalDate($year, 1, 1))->toEpochDay(), $end]]
                : [[$start, $end]];

            foreach ($segments as [$from, $until]) {
                for ($day = $from; $day <= $until; $day++) {
                    $date = LocalDate::fromEpochDay($day);
                    $key = $date->month * 100 + $date->day;

                    if (! isset($index[$key]) || $span < $spans[$key]) {
                        $index[$key] = $exception;
                        $spans[$key] = $span;
                    }
                }
            }
        }

        return $index;
    }
}
