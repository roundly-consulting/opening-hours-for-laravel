<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\ValueObjects;

use RoundlyConsulting\OpeningHours\Enums\DaySource;
use RoundlyConsulting\OpeningHours\Enums\Weekday;

/**
 * Weekdays sharing identical hours — `Mon–Fri 08:00–17:00`.
 */
final readonly class WeekdayGroup
{
    /**
     * @param  list<Weekday>  $days
     * @param  list<TimeRange>  $ranges
     */
    public function __construct(
        public array $days,
        public array $ranges,
    ) {}

    public function isClosed(): bool
    {
        return $this->ranges === [];
    }

    /**
     * `Mon–Fri` for a consecutive run, `Mon, Wed` otherwise.
     */
    public function label(?string $locale = null): string
    {
        $names = array_map(static fn (Weekday $day): string => $day->translated($locale, short: true), $this->days);

        if (count($this->days) < 2) {
            return implode('', $names);
        }

        $consecutive = true;

        for ($i = 1, $n = count($this->days); $i < $n; $i++) {
            if ($this->days[$i - 1]->next() !== $this->days[$i]) {
                $consecutive = false;
                break;
            }
        }

        if ($consecutive) {
            return $names[0].trans('opening-hours::messages.range_separator', [], $locale).$names[count($names) - 1];
        }

        return implode(', ', $names);
    }

    public function hoursText(?string $locale = null): string
    {
        return (new DayHours(null, $this->days[0] ?? Weekday::Monday, $this->ranges, DaySource::Schedule))->toString(locale: $locale);
    }

    /**
     * @return array{days: list<string>, label: string, ranges: list<array<string, mixed>>, text: string}
     */
    public function toArray(?string $locale = null): array
    {
        return [
            'days' => array_map(static fn (Weekday $day): string => $day->value, $this->days),
            'label' => $this->label($locale),
            'ranges' => array_map(static fn (TimeRange $range): array => $range->toArray(false), $this->ranges),
            'text' => $this->hoursText($locale),
        ];
    }
}
