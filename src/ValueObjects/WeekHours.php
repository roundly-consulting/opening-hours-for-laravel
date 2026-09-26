<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\ValueObjects;

use RoundlyConsulting\OpeningHours\Enums\DaySource;
use RoundlyConsulting\OpeningHours\Enums\Weekday;

/**
 * The regular hours of a week, seven `DayHours` ordered from the configured
 * first day of the week.
 */
final readonly class WeekHours
{
    /**
     * @param  list<DayHours>  $days  exactly one per weekday
     */
    public function __construct(public array $days) {}

    public function for(Weekday $weekday): DayHours
    {
        foreach ($this->days as $day) {
            if ($day->weekday === $weekday) {
                return $day;
            }
        }

        return new DayHours(null, $weekday, [], DaySource::None);
    }

    /**
     * @return list<DayHours>
     */
    public function days(): array
    {
        return $this->days;
    }

    /**
     * @return array<string, DayHours> keyed `monday` … `sunday`, in week order
     */
    public function keyed(): array
    {
        $keyed = [];

        foreach ($this->days as $day) {
            $keyed[$day->weekday->value] = $day;
        }

        return $keyed;
    }

    public function startingOn(Weekday $first): self
    {
        return new self(array_map(fn (Weekday $weekday): DayHours => $this->for($weekday), Weekday::ordered($first)));
    }

    /**
     * Days with identical hours grouped together. `$consecutiveOnly` groups only
     * neighbouring days (`Mon–Wed`, `Thu`, `Fri–Sat`); otherwise every day with
     * the same hours joins one group in first-seen order.
     *
     * @return list<WeekdayGroup>
     */
    public function grouped(bool $consecutiveOnly = true): array
    {
        /** @var list<string> $signatures */
        $signatures = [];
        /** @var list<list<Weekday>> $days */
        $days = [];
        /** @var list<list<TimeRange>> $ranges */
        $ranges = [];

        foreach ($this->days as $day) {
            $signature = implode(',', array_map(static fn (TimeRange $range): string => $range->toString('-'), $day->ranges));
            $target = null;

            if ($consecutiveOnly) {
                $last = count($signatures) - 1;

                if ($last >= 0 && $signatures[$last] === $signature) {
                    $target = $last;
                }
            } else {
                $found = array_search($signature, $signatures, true);
                $target = $found === false ? null : $found;
            }

            if ($target === null) {
                $signatures[] = $signature;
                $days[] = [$day->weekday];
                $ranges[] = $day->ranges;
            } else {
                $days[$target][] = $day->weekday;
            }
        }

        $groups = [];

        foreach ($signatures as $index => $signature) {
            $groups[] = new WeekdayGroup($days[$index], $ranges[$index]);
        }

        return $groups;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toArray(bool $withMeta = false, ?string $locale = null): array
    {
        return array_map(static fn (DayHours $day): array => $day->toArray($withMeta, $locale), $this->keyed());
    }
}
