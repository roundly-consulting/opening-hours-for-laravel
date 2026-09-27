<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\DataTransferObjects;

use RoundlyConsulting\OpeningHours\Exceptions\InvalidOpeningHoursException;
use RoundlyConsulting\OpeningHours\Validation\CalendarParser;
use RoundlyConsulting\OpeningHours\Validation\ParseOptions;
use RoundlyConsulting\OpeningHours\Validation\WeekArrayParser;

/**
 * A complete opening-hours definition: timezone, schedules, exceptions. The
 * one shape every write path, the cache and the engine agree on.
 */
final readonly class CalendarData
{
    /**
     * @param  list<ScheduleData>  $schedules
     * @param  list<ExceptionData>  $exceptions
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        public ?string $timezone = null,
        public ?string $label = null,
        public array $schedules = [],
        public array $exceptions = [],
        public ?array $meta = null,
        public ?int $revision = null,
    ) {}

    /**
     * Parse the canonical (untrusted) array shape; throws with every violation.
     *
     * @param  array<mixed>  $input
     *
     * @throws InvalidOpeningHoursException
     */
    public static function fromArray(array $input, ?ParseOptions $options = null): self
    {
        return CalendarParser::parse($input, $options ?? new ParseOptions)->dataOrFail();
    }

    /**
     * Parse the weekday-keyed week-array format.
     *
     * @param  array<mixed>  $input
     *
     * @throws InvalidOpeningHoursException
     */
    public static function fromWeekArray(array $input, ?ParseOptions $options = null): self
    {
        return WeekArrayParser::parse($input, $options ?? new ParseOptions)->dataOrFail();
    }

    /**
     * Rehydrate a payload this package produced itself (the cache). Structural
     * checks only; a malformed payload throws and the caller treats it as a miss.
     *
     * @param  array<mixed>  $cached
     *
     * @throws InvalidOpeningHoursException
     */
    public static function fromTrustedArray(array $cached): self
    {
        return CalendarParser::parse($cached, new ParseOptions, trusted: true)->dataOrFail();
    }

    public function baseSchedule(): ?ScheduleData
    {
        foreach ($this->schedules as $schedule) {
            if ($schedule->isBase()) {
                return $schedule;
            }
        }

        return null;
    }

    public function withRevision(?int $revision): self
    {
        return new self($this->timezone, $this->label, $this->schedules, $this->exceptions, $this->meta, $revision);
    }

    public function withTimezone(?string $timezone): self
    {
        return new self($timezone, $this->label, $this->schedules, $this->exceptions, $this->meta, $this->revision);
    }

    /**
     * The canonical long form; round-trips through `fromArray()`.
     *
     * @return array{timezone: ?string, label: ?string, schedules: list<array<string, mixed>>, exceptions: list<array<string, mixed>>, meta?: array<string, mixed>|null}
     */
    public function toArray(bool $withMeta = true): array
    {
        $array = [
            'timezone' => $this->timezone,
            'label' => $this->label,
            'schedules' => array_map(static fn (ScheduleData $schedule): array => $schedule->toArray($withMeta), $this->schedules),
            'exceptions' => array_map(static fn (ExceptionData $exception): array => $exception->toArray($withMeta), $this->exceptions),
        ];

        if ($withMeta) {
            $array['meta'] = $this->meta;
        }

        return $array;
    }
}
