<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Engine;

use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ScheduleData;
use RoundlyConsulting\OpeningHours\Enums\Recurrence;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\OpeningHours\ValueObjects\StructuredData;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;
use RoundlyConsulting\OpeningHours\ValueObjects\YearlyWindow;

/**
 * Builds schema.org `OpeningHoursSpecification` items: the regular ranges of
 * the schedule in effect, plus upcoming one-off exceptions with validity dates.
 *
 * @internal
 */
final class StructuredDataBuilder
{
    public static function build(Definition $definition, LocalDate $asOf, int $upcomingDays): StructuredData
    {
        $items = [];
        $schedule = $definition->scheduleFor($asOf);

        if ($schedule !== null) {
            $validity = self::validity($schedule, $asOf);

            foreach (Weekday::ordered() as $weekday) {
                foreach ($schedule->week->for($weekday) as $range) {
                    $items[] = [
                        '@type' => 'OpeningHoursSpecification',
                        'dayOfWeek' => 'https://schema.org/'.ucfirst($weekday->value),
                        ...self::times($range),
                        ...$validity,
                    ];
                }
            }
        }

        $horizon = $asOf->addDays($upcomingDays);

        foreach ($definition->data->exceptions as $exception) {
            if (! self::upcoming($exception, $asOf, $horizon)) {
                continue;
            }

            /** @var AbsoluteWindow $window */
            $window = $exception->window;
            $validity = [
                'validFrom' => (string) $window->from?->toDateString(),
                'validThrough' => (string) $window->until?->toDateString(),
            ];

            if ($exception->isClosed()) {
                $items[] = ['@type' => 'OpeningHoursSpecification', 'opens' => '00:00', 'closes' => '00:00', ...$validity];

                continue;
            }

            foreach ($exception->ranges as $range) {
                $items[] = ['@type' => 'OpeningHoursSpecification', ...self::times($range), ...$validity];
            }
        }

        return new StructuredData($items);
    }

    /**
     * @return array{opens: string, closes: string}
     */
    private static function times(TimeRange $range): array
    {
        return [
            'opens' => $range->start->format(),
            'closes' => $range->end->isMidnightEnd() ? '23:59' : $range->end->format(),
        ];
    }

    private static function upcoming(ExceptionData $exception, LocalDate $from, LocalDate $until): bool
    {
        if ($exception->recurrence() !== Recurrence::None) {
            return false;
        }

        return $exception->window->overlaps(new AbsoluteWindow($from, $until));
    }

    /**
     * The seasonal schedule's current (or next) concrete occurrence.
     *
     * @return array<string, string>
     */
    private static function validity(ScheduleData $schedule, LocalDate $asOf): array
    {
        if ($schedule->isBase()) {
            return [];
        }

        $window = $schedule->window;

        if ($window instanceof AbsoluteWindow) {
            return array_filter([
                'validFrom' => (string) $window->from?->toDateString(),
                'validThrough' => (string) $window->until?->toDateString(),
            ], static fn (string $value): bool => $value !== '');
        }

        if (! $window instanceof YearlyWindow) {
            return [];
        }

        $year = $asOf->year;
        $start = $window->from->toDateIn($year);
        $end = $window->until->toDateIn($window->wraps() ? $year + 1 : $year, asEnd: true);

        if ($window->wraps() && ! $asOf->isAfter($window->until->toDateIn($year, asEnd: true))) {
            $start = $window->from->toDateIn($year - 1);
            $end = $window->until->toDateIn($year, asEnd: true);
        } elseif ($asOf->isAfter($end)) {
            $start = $window->from->toDateIn($year + 1);
            $end = $window->until->toDateIn($window->wraps() ? $year + 2 : $year + 1, asEnd: true);
        }

        return ['validFrom' => $start->toDateString(), 'validThrough' => $end->toDateString()];
    }
}
