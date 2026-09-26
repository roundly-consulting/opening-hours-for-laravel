<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\ValueObjects;

use RoundlyConsulting\OpeningHours\Enums\DaySource;
use RoundlyConsulting\OpeningHours\Enums\Weekday;

/**
 * The wall-clock hours of one day: a real date (`forDate()`) or a regular
 * weekday (`forWeekday()`, `date === null`). Ranges are as defined — an
 * overnight range is listed on the day it starts.
 */
final readonly class DayHours
{
    /**
     * @param  list<TimeRange>  $ranges
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        public ?LocalDate $date,
        public Weekday $weekday,
        public array $ranges,
        public DaySource $source,
        public ?string $label = null,
        public ?array $meta = null,
    ) {}

    public function isClosed(): bool
    {
        return $this->ranges === [];
    }

    public function isOpen(): bool
    {
        return $this->ranges !== [];
    }

    /**
     * Open for the whole wall-clock day, `00:00` to `24:00`.
     */
    public function isOpenAllDay(): bool
    {
        $segments = [];

        foreach ($this->ranges as $range) {
            $segments[] = [$range->start->minutes, $range->isOvernight() ? Time::END_OF_DAY : $range->end->minutes];
        }

        usort($segments, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $covered = 0;

        foreach ($segments as [$start, $end]) {
            if ($start > $covered) {
                return false;
            }

            $covered = max($covered, $end);
        }

        return $covered >= Time::END_OF_DAY;
    }

    /**
     * Wall-clock check; an overnight range counts only until midnight here — the
     * spill belongs to the next day's view.
     */
    public function isOpenAt(Time $time): bool
    {
        foreach ($this->ranges as $range) {
            $end = $range->isOvernight() ? Time::END_OF_DAY : $range->end->minutes;

            if ($time->minutes >= $range->start->minutes && $time->minutes < $end) {
                return true;
            }
        }

        return false;
    }

    public function totalMinutes(): int
    {
        return array_sum(array_map(static fn (TimeRange $range): int => $range->durationMinutes(), $this->ranges));
    }

    /**
     * `09:00–12:00, 13:00–17:00`. With `$closedText === null` a closed day reads
     * the translated "Closed" and a single all-day range "Open 24 hours"; pass an
     * explicit `$closedText` (even `''`) for literal output.
     */
    public function toString(string $rangeSeparator = ', ', string $timeSeparator = '–', ?string $locale = null, ?string $closedText = null): string
    {
        if ($this->isClosed()) {
            return $closedText ?? (string) trans('opening-hours::messages.closed', [], $locale);
        }

        if ($closedText === null && count($this->ranges) === 1 && $this->ranges[0]->isAllDay()) {
            return (string) trans('opening-hours::messages.open_24_hours', [], $locale);
        }

        return implode($rangeSeparator, array_map(
            static fn (TimeRange $range): string => $range->toString($timeSeparator),
            $this->ranges,
        ));
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * @return array{date: ?string, weekday: string, weekday_label: string, closed: bool, all_day: bool, source: string, label: ?string, ranges: list<array<string, mixed>>, text: string, meta?: array<string, mixed>|null}
     */
    public function toArray(bool $withMeta = false, ?string $locale = null): array
    {
        $array = [
            'date' => $this->date?->toDateString(),
            'weekday' => $this->weekday->value,
            'weekday_label' => $this->weekday->translated($locale),
            'closed' => $this->isClosed(),
            'all_day' => $this->isOpenAllDay(),
            'source' => $this->source->value,
            'label' => $this->label,
            'ranges' => array_map(static fn (TimeRange $range): array => $range->toArray($withMeta), $this->ranges),
            'text' => $this->toString(locale: $locale),
        ];

        if ($withMeta) {
            $array['meta'] = $this->meta;
        }

        return $array;
    }
}
