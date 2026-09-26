<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\ValueObjects;

use RoundlyConsulting\OpeningHours\Exceptions\InvalidTimeException;

/**
 * A wall-clock opening range `HH:MM-HH:MM` with minute precision. An end at or
 * before the start makes the range overnight (it ends the next day); an end of
 * `00:00` is read as `24:00`. The range always belongs to the day it starts on.
 */
final readonly class TimeRange
{
    public Time $end;

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        public Time $start,
        Time $end,
        public ?string $label = null,
        public ?int $capacity = null,
        public ?array $meta = null,
    ) {
        $text = $start->format().'-'.$end->format();

        if ($start->isMidnightEnd()) {
            throw InvalidTimeException::startAt24($text);
        }

        if ($start->minutes === $end->minutes) {
            throw InvalidTimeException::emptyRange($text);
        }

        if ($capacity !== null && ($capacity < 1 || $capacity > 1000)) {
            throw InvalidTimeException::invalidCapacity($capacity);
        }

        $this->end = $end->minutes === 0 ? new Time(Time::END_OF_DAY) : $end;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function fromString(string $value, ?string $label = null, ?int $capacity = null, ?array $meta = null): self
    {
        $parts = preg_split('/\s*(?:-|–)\s*/u', trim($value));

        if ($parts === false || count($parts) !== 2) {
            throw InvalidTimeException::invalidRange($value);
        }

        return new self(Time::fromString($parts[0]), Time::fromString($parts[1]), $label, $capacity, $meta);
    }

    public static function fromMinutes(int $start, int $end, ?string $label = null, ?int $capacity = null): self
    {
        return new self(new Time($start), new Time($end), $label, $capacity);
    }

    public static function allDay(?string $label = null, ?int $capacity = null): self
    {
        return new self(new Time(0), new Time(Time::END_OF_DAY), $label, $capacity);
    }

    public function isOvernight(): bool
    {
        return $this->end->minutes < $this->start->minutes;
    }

    public function isAllDay(): bool
    {
        return $this->start->minutes === 0 && $this->end->isMidnightEnd();
    }

    /**
     * Wall-clock minutes (DST days are longer or shorter in real time).
     */
    public function durationMinutes(): int
    {
        return $this->isOvernight()
            ? Time::END_OF_DAY - $this->start->minutes + $this->end->minutes
            : $this->end->minutes - $this->start->minutes;
    }

    /**
     * The end measured from the start day's midnight (`> 1440` when overnight).
     */
    public function endOffsetMinutes(): int
    {
        return $this->isOvernight() ? $this->end->minutes + Time::END_OF_DAY : $this->end->minutes;
    }

    public function toString(string $separator = '–'): string
    {
        return $this->start->format().$separator.$this->end->format();
    }

    /**
     * Same times, label, capacity and meta.
     */
    public function equals(self $other): bool
    {
        return $this->start->minutes === $other->start->minutes
            && $this->end->minutes === $other->end->minutes
            && $this->label === $other->label
            && $this->capacity === $other->capacity
            && $this->meta === $other->meta;
    }

    /**
     * @return array{from: string, to: string, label: ?string, capacity: ?int, meta?: array<string, mixed>|null}
     */
    public function toArray(bool $withMeta = true): array
    {
        $array = [
            'from' => $this->start->format(),
            'to' => $this->end->format(),
            'label' => $this->label,
            'capacity' => $this->capacity,
        ];

        if ($withMeta) {
            $array['meta'] = $this->meta;
        }

        return $array;
    }
}
