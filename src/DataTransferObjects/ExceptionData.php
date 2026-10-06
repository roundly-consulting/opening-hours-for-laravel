<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\DataTransferObjects;

use RoundlyConsulting\OpeningHours\Contracts\DateWindow;
use RoundlyConsulting\OpeningHours\Enums\Recurrence;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidDateException;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidTimeException;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\OpeningHours\ValueObjects\MonthDay;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;
use RoundlyConsulting\OpeningHours\ValueObjects\YearlyWindow;

/**
 * An exception rule: on every date of its window the given ranges replace the
 * schedule (no ranges = closed).
 */
final readonly class ExceptionData
{
    /**
     * @param  list<TimeRange>  $ranges
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        public DateWindow $window,
        public array $ranges = [],
        public ?string $label = null,
        public ?array $meta = null,
        public ?int $id = null,
    ) {}

    /**
     * Custom hours on a date or date span (`Y-m-d`), or every year (`m-d`, or
     * `yearly: true`). No ranges means closed.
     *
     * @param  array<TimeRange|string>  $ranges
     * @param  array<string, mixed>|null  $meta
     *
     * @throws InvalidDateException
     * @throws InvalidTimeException
     */
    public static function make(
        LocalDate|string $from,
        LocalDate|string|null $until = null,
        array $ranges = [],
        ?string $label = null,
        bool $yearly = false,
        ?array $meta = null,
    ): self {
        $until ??= $from;

        $window = $yearly || (is_string($from) && MonthDay::isValid($from))
            ? new YearlyWindow(self::monthDay($from), self::monthDay($until))
            : new AbsoluteWindow(self::date($from), self::date($until));

        return new self(
            $window,
            // A list whatever keys the caller's array had (`array_filter()`, named entries).
            array_values(array_map(
                static fn (TimeRange|string $range): TimeRange => $range instanceof TimeRange ? $range : TimeRange::fromString($range),
                $ranges,
            )),
            $label,
            $meta,
        );
    }

    public function isClosed(): bool
    {
        return $this->ranges === [];
    }

    public function recurrence(): Recurrence
    {
        return $this->window->recurrence();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $withMeta = true): array
    {
        $window = $this->window->toArray();

        $array = [
            'id' => $this->id,
            'from' => $window['from'],
            'until' => $window['until'],
            'recurrence' => $window['recurrence'],
            'ranges' => array_map(static fn (TimeRange $range): array => $range->toArray($withMeta), $this->ranges),
            'label' => $this->label,
        ];

        if ($withMeta) {
            $array['meta'] = $this->meta;
        }

        return $array;
    }

    private static function date(LocalDate|string $date): LocalDate
    {
        return $date instanceof LocalDate ? $date : LocalDate::fromString($date);
    }

    private static function monthDay(LocalDate|string $date): MonthDay
    {
        return $date instanceof LocalDate ? $date->monthDay() : MonthDay::fromString($date);
    }
}
