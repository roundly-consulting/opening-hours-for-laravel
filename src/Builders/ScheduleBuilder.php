<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Builders;

use RoundlyConsulting\OpeningHours\Contracts\DateWindow;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ScheduleData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\WeekData;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\OpeningHours\ValueObjects\MonthDay;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;
use RoundlyConsulting\OpeningHours\ValueObjects\YearlyWindow;

/**
 * Fluent weekly schedule. Day setters replace that day's ranges.
 */
final class ScheduleBuilder
{
    /** @var array<int, list<TimeRange>> */
    private array $ranges = [];

    private ?DateWindow $window = null;

    private ?LocalDate $from = null;

    private ?LocalDate $until = null;

    private int $priority = 0;

    private ?string $label = null;

    /** @var array<string, mixed>|null */
    private ?array $meta = null;

    private ?int $id = null;

    public static function make(): self
    {
        return new self;
    }

    public static function fromData(ScheduleData $schedule): self
    {
        $builder = new self;
        $builder->ranges = $schedule->week->ranges;
        $window = $schedule->isBase() ? null : $schedule->window;

        // A dated window is kept as its bounds, so changing one keeps the other.
        if ($window instanceof AbsoluteWindow) {
            $builder->from = $window->from;
            $builder->until = $window->until;
        } else {
            $builder->window = $window;
        }

        $builder->priority = $schedule->priority;
        $builder->label = $schedule->label;
        $builder->meta = $schedule->meta;
        $builder->id = $schedule->id;

        return $builder;
    }

    public function id(?int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function label(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function meta(?array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }

    public function priority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function between(LocalDate|string $from, LocalDate|string $until): self
    {
        $this->window = null;
        $this->from = self::date($from);
        $this->until = self::date($until);

        return $this;
    }

    public function from(LocalDate|string $date): self
    {
        $this->window = null;
        $this->from = self::date($date);

        return $this;
    }

    public function until(LocalDate|string $date): self
    {
        $this->window = null;
        $this->until = self::date($date);

        return $this;
    }

    /**
     * Every year from `m-d` to `m-d` (may wrap the year end).
     */
    public function yearly(string $from, string $until): self
    {
        $this->from = $this->until = null;
        $this->window = new YearlyWindow(MonthDay::fromString($from), MonthDay::fromString($until));

        return $this;
    }

    public function monday(TimeRange|string ...$ranges): self
    {
        return $this->days([Weekday::Monday], ...$ranges);
    }

    public function tuesday(TimeRange|string ...$ranges): self
    {
        return $this->days([Weekday::Tuesday], ...$ranges);
    }

    public function wednesday(TimeRange|string ...$ranges): self
    {
        return $this->days([Weekday::Wednesday], ...$ranges);
    }

    public function thursday(TimeRange|string ...$ranges): self
    {
        return $this->days([Weekday::Thursday], ...$ranges);
    }

    public function friday(TimeRange|string ...$ranges): self
    {
        return $this->days([Weekday::Friday], ...$ranges);
    }

    public function saturday(TimeRange|string ...$ranges): self
    {
        return $this->days([Weekday::Saturday], ...$ranges);
    }

    public function sunday(TimeRange|string ...$ranges): self
    {
        return $this->days([Weekday::Sunday], ...$ranges);
    }

    /**
     * Monday to Friday.
     */
    public function weekdays(TimeRange|string ...$ranges): self
    {
        return $this->days([Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday], ...$ranges);
    }

    public function weekend(TimeRange|string ...$ranges): self
    {
        return $this->days([Weekday::Saturday, Weekday::Sunday], ...$ranges);
    }

    public function everyDay(TimeRange|string ...$ranges): self
    {
        return $this->days(Weekday::ordered(), ...$ranges);
    }

    /**
     * @param  list<Weekday>  $days
     */
    public function days(array $days, TimeRange|string ...$ranges): self
    {
        $parsed = array_values(array_map(
            static fn (TimeRange|string $range): TimeRange => $range instanceof TimeRange ? $range : TimeRange::fromString($range),
            $ranges,
        ));

        foreach ($days as $day) {
            $this->ranges[$day->iso()] = $parsed;
        }

        return $this;
    }

    public function closedOn(Weekday ...$days): self
    {
        foreach ($days as $day) {
            unset($this->ranges[$day->iso()]);
        }

        return $this;
    }

    public function open24Hours(Weekday ...$days): self
    {
        return $this->days(array_values($days), TimeRange::allDay());
    }

    public function toData(): ScheduleData
    {
        $window = $this->window;

        if ($window === null && ($this->from !== null || $this->until !== null)) {
            $window = new AbsoluteWindow($this->from, $this->until);
        }

        return new ScheduleData(new WeekData($this->ranges), $window, $this->priority, $this->label, $this->meta, $this->id);
    }

    public function withoutWindow(): self
    {
        $this->window = null;
        $this->from = $this->until = null;

        return $this;
    }

    private static function date(LocalDate|string $date): LocalDate
    {
        return $date instanceof LocalDate ? $date : LocalDate::fromString($date);
    }
}
