<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Builders;

use Closure;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ScheduleData;
use RoundlyConsulting\OpeningHours\Enums\ViolationCode;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOpeningHoursException;
use RoundlyConsulting\OpeningHours\OpeningHours;
use RoundlyConsulting\OpeningHours\Validation\DefinitionValidator;
use RoundlyConsulting\OpeningHours\Validation\RangeNormalizer;
use RoundlyConsulting\OpeningHours\Validation\Violation;
use RoundlyConsulting\OpeningHours\Validation\ViolationList;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;

/**
 * Fluent editor for a whole calendar. Attached to an owner (`editOpeningHours()`)
 * it starts from the current definition and `save()` writes it back
 * optimistically: a concurrent write in between throws
 * `StaleOpeningHoursException` instead of being silently overwritten.
 * Detached (`CalendarBuilder::make()`), `build()` returns an in-memory `OpeningHours`.
 */
final class CalendarBuilder
{
    private ?string $timezone = null;

    private ?string $label = null;

    /** @var array<string, mixed>|null */
    private ?array $meta = null;

    /** @var list<ScheduleData> */
    private array $schedules = [];

    /** @var list<ExceptionData> */
    private array $exceptions = [];

    private bool $mergeOverlapping = false;

    private ?int $expectedRevision = null;

    private bool $ignoreConcurrentChanges = false;

    /** @var (Closure(CalendarData, ?int): OpeningHours)|null */
    private ?Closure $saver = null;

    public static function make(?CalendarData $from = null): self
    {
        $builder = new self;

        if ($from !== null) {
            $builder->timezone = $from->timezone;
            $builder->label = $from->label;
            $builder->meta = $from->meta;
            $builder->schedules = $from->schedules;
            $builder->exceptions = $from->exceptions;
        }

        return $builder;
    }

    /**
     * @internal wires the builder to a persisted calendar
     *
     * @param  Closure(CalendarData, ?int): OpeningHours  $saver
     */
    public static function attached(?CalendarData $current, int $loadedRevision, Closure $saver): self
    {
        $builder = self::make($current);
        $builder->expectedRevision = $loadedRevision;
        $builder->saver = $saver;

        return $builder;
    }

    public function timezone(?string $timezone): self
    {
        $this->timezone = $timezone;

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

    /**
     * Replace (or create) the base schedule — the one without a window.
     *
     * @param  (Closure(ScheduleBuilder): (ScheduleBuilder|null|void))|ScheduleData  $schedule
     */
    public function baseSchedule(Closure|ScheduleData $schedule): self
    {
        $index = null;

        foreach ($this->schedules as $i => $existing) {
            if ($existing->isBase()) {
                $index = $i;
                break;
            }
        }

        if ($schedule instanceof Closure) {
            $builder = ScheduleBuilder::make()->id($index === null ? null : $this->schedules[$index]->id);
            $result = $schedule($builder);
            $schedule = ($result instanceof ScheduleBuilder ? $result : $builder)->withoutWindow()->toData();
        }

        if ($index === null) {
            $this->schedules[] = $schedule;
        } else {
            $schedules = $this->schedules;
            $schedules[$index] = $schedule;
            $this->schedules = array_values($schedules);
        }

        return $this;
    }

    /**
     * Add a (usually seasonal) schedule.
     *
     * @param  (Closure(ScheduleBuilder): (ScheduleBuilder|null|void))|ScheduleData  $schedule
     */
    public function schedule(Closure|ScheduleData $schedule): self
    {
        if ($schedule instanceof Closure) {
            $builder = ScheduleBuilder::make();
            $result = $schedule($builder);
            $schedule = ($result instanceof ScheduleBuilder ? $result : $builder)->toData();
        }

        $this->schedules[] = $schedule;

        return $this;
    }

    public function removeSchedule(int $id): self
    {
        $this->schedules = $this->without($this->schedules, $id, 'schedules');

        return $this;
    }

    public function withoutSchedules(): self
    {
        $this->schedules = [];

        return $this;
    }

    /**
     * Custom hours on a date or date span (`Y-m-d`), or every year (`m-d`,
     * or `yearly: true`). No ranges means closed.
     *
     * @param  list<TimeRange|string>  $ranges
     * @param  array<string, mixed>|null  $meta
     */
    public function exception(
        LocalDate|string $from,
        LocalDate|string|null $until = null,
        array $ranges = [],
        ?string $label = null,
        bool $yearly = false,
        ?array $meta = null,
    ): self {
        $this->exceptions[] = ExceptionData::make($from, $until, $ranges, $label, $yearly, $meta);

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function closed(LocalDate|string $from, LocalDate|string|null $until = null, ?string $label = null, bool $yearly = false, ?array $meta = null): self
    {
        return $this->exception($from, $until, [], $label, $yearly, $meta);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function closedYearly(string $from, ?string $until = null, ?string $label = null, ?array $meta = null): self
    {
        return $this->exception($from, $until, [], $label, true, $meta);
    }

    public function removeException(int $id): self
    {
        $this->exceptions = $this->without($this->exceptions, $id, 'exceptions');

        return $this;
    }

    public function withoutExceptions(): self
    {
        $this->exceptions = [];

        return $this;
    }

    public function mergeOverlapping(bool $merge = true): self
    {
        $this->mergeOverlapping = $merge;

        return $this;
    }

    /**
     * Save only if the calendar is still at this revision (e.g. the one an HTTP
     * client echoed back). Overrides the revision captured by `edit()`.
     */
    public function expectRevision(int $revision): self
    {
        $this->expectedRevision = $revision;
        $this->ignoreConcurrentChanges = false;

        return $this;
    }

    /**
     * Last writer wins: skip the optimistic revision check.
     */
    public function ignoreConcurrentChanges(): self
    {
        $this->ignoreConcurrentChanges = true;

        return $this;
    }

    public function toData(): CalendarData
    {
        $data = new CalendarData($this->timezone, $this->label, $this->schedules, $this->exceptions, $this->meta);

        return $this->mergeOverlapping ? RangeNormalizer::normalize($data) : $data;
    }

    public function violations(): ViolationList
    {
        return DefinitionValidator::validate($this->toData());
    }

    /**
     * An in-memory `OpeningHours` (no database).
     *
     * @throws InvalidOpeningHoursException
     */
    public function build(): OpeningHours
    {
        return OpeningHours::make($this->validData());
    }

    /**
     * Validate and write the definition back to the owner's calendar.
     *
     * @throws InvalidOpeningHoursException
     */
    public function save(): OpeningHours
    {
        if ($this->saver === null) {
            return $this->build();
        }

        return ($this->saver)($this->validData(), $this->ignoreConcurrentChanges ? null : $this->expectedRevision);
    }

    private function validData(): CalendarData
    {
        $data = $this->toData();
        $violations = DefinitionValidator::validate($data);

        if (! $violations->isEmpty()) {
            throw InvalidOpeningHoursException::withViolations($violations);
        }

        return $data;
    }

    /**
     * @template T of ScheduleData|ExceptionData
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    private function without(array $items, int $id, string $path): array
    {
        $kept = array_values(array_filter($items, static fn (ScheduleData|ExceptionData $item): bool => $item->id !== $id));

        if (count($kept) === count($items)) {
            throw InvalidOpeningHoursException::fromViolation(new Violation(ViolationCode::UnknownId, $path, ['id' => $id]));
        }

        return $kept;
    }
}
