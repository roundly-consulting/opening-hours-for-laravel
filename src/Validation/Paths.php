<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Validation;

use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\Enums\Weekday;

/**
 * Maps positions in a `CalendarData` back to paths in the input it came from,
 * so semantic violations point at what the caller actually sent.
 *
 * @internal
 */
final class Paths
{
    /**
     * @param  array<int, string>  $schedules
     * @param  array<int, array<int, array<int, string>>>  $scheduleRanges  schedule => iso weekday => range paths
     * @param  array<int, string>  $scheduleDays  schedule * 10 + iso => day path
     * @param  array<int, string>  $exceptions
     * @param  array<int, array<int, string>>  $exceptionRanges
     */
    public function __construct(
        public array $schedules = [],
        public array $scheduleRanges = [],
        public array $scheduleDays = [],
        public array $exceptions = [],
        public array $exceptionRanges = [],
    ) {}

    public static function canonical(CalendarData $data): self
    {
        $paths = new self;

        foreach ($data->schedules as $i => $schedule) {
            $paths->schedules[$i] = "schedules.{$i}";

            foreach ($schedule->week->ranges as $iso => $ranges) {
                $day = Weekday::fromIso($iso)->value;
                $paths->scheduleDays[$i * 10 + $iso] = "schedules.{$i}.week.{$day}";

                foreach (array_keys($ranges) as $k) {
                    $paths->scheduleRanges[$i][$iso][$k] = "schedules.{$i}.week.{$day}.{$k}";
                }
            }
        }

        foreach ($data->exceptions as $i => $exception) {
            $paths->exceptions[$i] = "exceptions.{$i}";

            foreach (array_keys($exception->ranges) as $k) {
                $paths->exceptionRanges[$i][$k] = "exceptions.{$i}.ranges.{$k}";
            }
        }

        return $paths;
    }

    public function schedule(int $index): string
    {
        return $this->schedules[$index] ?? "schedules.{$index}";
    }

    public function scheduleDay(int $index, Weekday $day): string
    {
        return $this->scheduleDays[$index * 10 + $day->iso()] ?? $this->schedule($index).'.week.'.$day->value;
    }

    public function scheduleRange(int $index, Weekday $day, int $position): string
    {
        return $this->scheduleRanges[$index][$day->iso()][$position] ?? $this->scheduleDay($index, $day).'.'.$position;
    }

    public function exception(int $index): string
    {
        return $this->exceptions[$index] ?? "exceptions.{$index}";
    }

    public function exceptionRange(int $index, int $position): string
    {
        return $this->exceptionRanges[$index][$position] ?? $this->exception($index).'.ranges.'.$position;
    }
}
