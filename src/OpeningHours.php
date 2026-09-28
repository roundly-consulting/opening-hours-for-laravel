<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use DateTimeInterface;
use DateTimeZone;
use RoundlyConsulting\OpeningHours\Availability\Availability;
use RoundlyConsulting\OpeningHours\Contracts\DynamicExceptionProvider;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\Engine\Compiler;
use RoundlyConsulting\OpeningHours\Engine\DayResolver;
use RoundlyConsulting\OpeningHours\Engine\Definition;
use RoundlyConsulting\OpeningHours\Engine\PeriodTimeline;
use RoundlyConsulting\OpeningHours\Engine\Run;
use RoundlyConsulting\OpeningHours\Engine\StructuredDataBuilder;
use RoundlyConsulting\OpeningHours\Engine\WallClock;
use RoundlyConsulting\OpeningHours\Engine\WeekCoverage;
use RoundlyConsulting\OpeningHours\Enums\DaySource;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\Exceptions\QueryRangeTooLargeException;
use RoundlyConsulting\OpeningHours\Support\Clock;
use RoundlyConsulting\OpeningHours\Support\Settings;
use RoundlyConsulting\OpeningHours\Support\TimezoneResolver;
use RoundlyConsulting\OpeningHours\ValueObjects\DayHours;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\OpeningHours\ValueObjects\OpeningPeriod;
use RoundlyConsulting\OpeningHours\ValueObjects\StructuredData;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;
use RoundlyConsulting\OpeningHours\ValueObjects\WeekHours;

/**
 * The immutable query object: is it open, when does it open or close next, what
 * are the hours of a date or week. Every wall-clock boundary is resolved with
 * one DST rule (the first instant the local clock reads at or after it), and
 * every instant it returns is a `CarbonImmutable` in the output timezone
 * (the calendar's own unless `withOutputTimezone()` says otherwise).
 */
final class OpeningHours
{
    private ?bool $alwaysOpen = null;

    /**
     * @param  list<DynamicExceptionProvider>  $providers
     */
    private function __construct(
        private readonly Definition $definition,
        private readonly DateTimeZone $timezone,
        private readonly DateTimeZone $outputTimezone,
        private readonly array $providers,
        private readonly PeriodTimeline $timeline,
    ) {}

    /**
     * An in-memory calendar (no database). Timezone: `$timezone` → the data's
     * own → `opening-hours.timezone` → `app.timezone`.
     *
     * @param  CalendarData|array<mixed>  $data
     */
    public static function make(CalendarData|array $data, DateTimeZone|string|null $timezone = null): self
    {
        $data = is_array($data) ? CalendarData::fromArray($data) : $data;

        return self::fromDefinition(Compiler::compile($data), TimezoneResolver::resolve($timezone, $data->timezone));
    }

    public static function empty(DateTimeZone|string|null $timezone = null): self
    {
        return self::make(new CalendarData, $timezone);
    }

    /**
     * @internal
     *
     * @param  list<DynamicExceptionProvider>  $providers
     */
    public static function fromDefinition(Definition $definition, DateTimeZone $timezone, array $providers = []): self
    {
        return new self(
            $definition,
            $timezone,
            $timezone,
            $providers,
            new PeriodTimeline(WallClock::for($timezone), new DayResolver($definition, $providers)),
        );
    }

    // ───────────────────────────── Context ─────────────────────────────

    public function timezone(): DateTimeZone
    {
        return $this->timezone;
    }

    public function outputTimezone(): DateTimeZone
    {
        return $this->outputTimezone;
    }

    /**
     * The same hours, reported in another timezone. Semantics never change.
     */
    public function withOutputTimezone(DateTimeZone|string $timezone): self
    {
        return new self(
            $this->definition,
            $this->timezone,
            TimezoneResolver::validate($timezone),
            $this->providers,
            $this->timeline,
        );
    }

    public function withDynamicExceptions(DynamicExceptionProvider ...$providers): self
    {
        $all = [...$this->providers, ...array_values($providers)];

        return new self(
            $this->definition,
            $this->timezone,
            $this->outputTimezone,
            $all,
            new PeriodTimeline($this->timeline->clock, new DayResolver($this->definition, $all)),
        );
    }

    public function definition(): CalendarData
    {
        return $this->definition->data;
    }

    public function revision(): ?int
    {
        return $this->definition->data->revision;
    }

    /**
     * @internal
     */
    public function timeline(): PeriodTimeline
    {
        return $this->timeline;
    }

    /**
     * @internal
     */
    public function compiled(): Definition
    {
        return $this->definition;
    }

    // ─────────────────────────── Point in time ───────────────────────────

    public function isOpen(): bool
    {
        return $this->isOpenAt(Clock::now());
    }

    public function isClosed(): bool
    {
        return ! $this->isOpen();
    }

    public function isOpenAt(DateTimeInterface $at): bool
    {
        return $this->timeline->isOpenAt($at->getTimestamp());
    }

    public function isClosedAt(DateTimeInterface $at): bool
    {
        return ! $this->isOpenAt($at);
    }

    /**
     * The coalesced opening run containing the instant, or null when closed.
     */
    public function currentPeriod(?DateTimeInterface $at = null): ?OpeningPeriod
    {
        $instant = ($at ?? Clock::now())->getTimestamp();
        $limit = Settings::searchDays();

        $run = $this->first($this->timeline->forward($instant, $limit));

        if ($run === null || $run->start > $instant) {
            return null;
        }

        if ($run->start < $instant) {
            $earlier = $this->first($this->timeline->backward($instant, $limit));

            if ($earlier !== null) {
                $run->start = $earlier->start;
                $run->startsBeforeScan = $earlier->startsBeforeScan;
            }
        }

        return $this->period($run);
    }

    /**
     * The defined wall-clock range whose period contains the instant (label and
     * capacity intact), or null when closed.
     */
    public function currentRange(?DateTimeInterface $at = null): ?TimeRange
    {
        return $this->timeline->rawPeriodAt(($at ?? Clock::now())->getTimestamp())?->range;
    }

    // ───────────────────────────── Navigation ─────────────────────────────

    /**
     * The next opening strictly after `$after`, within `$searchDays` local days
     * after its day (default `search_days`); null when there is none.
     */
    public function nextOpen(?DateTimeInterface $after = null, ?int $searchDays = null): ?CarbonImmutable
    {
        return $this->nextPeriod($after, $searchDays)?->start;
    }

    /**
     * The next closing strictly after `$after`, or null (always open, or not
     * within the search window).
     */
    public function nextClose(?DateTimeInterface $after = null, ?int $searchDays = null): ?CarbonImmutable
    {
        if ($this->isAlwaysOpen()) {
            return null;
        }

        $instant = ($after ?? Clock::now())->getTimestamp();
        $limit = $searchDays ?? Settings::searchDays();

        foreach ($this->timeline->forward($instant, $limit) as $run) {
            return $run->endsAfterScan || $run->start >= $this->timeline->forwardEdge($instant, $limit) ? null : $this->instant($run->end);
        }

        return null;
    }

    /**
     * The latest opening strictly before `$before`, or null.
     */
    public function previousOpen(?DateTimeInterface $before = null, ?int $searchDays = null): ?CarbonImmutable
    {
        $instant = ($before ?? Clock::now())->getTimestamp();

        foreach ($this->timeline->backward($instant, $searchDays ?? Settings::searchDays()) as $run) {
            return $run->startsBeforeScan ? null : $this->instant($run->start);
        }

        return null;
    }

    /**
     * The latest closing strictly before `$before`, or null.
     */
    public function previousClose(?DateTimeInterface $before = null, ?int $searchDays = null): ?CarbonImmutable
    {
        if ($this->isAlwaysOpen()) {
            return null;
        }

        $instant = ($before ?? Clock::now())->getTimestamp();

        foreach ($this->timeline->backward($instant, $searchDays ?? Settings::searchDays()) as $run) {
            if ($run->end < $instant) {
                return $this->instant($run->end);
            }
        }

        return null;
    }

    /**
     * The next opening run starting strictly after `$after`.
     */
    public function nextPeriod(?DateTimeInterface $after = null, ?int $searchDays = null): ?OpeningPeriod
    {
        $instant = ($after ?? Clock::now())->getTimestamp();
        $limit = $searchDays ?? Settings::searchDays();
        $edge = $this->timeline->forwardEdge($instant, $limit);

        foreach ($this->timeline->forward($instant, $limit) as $run) {
            if ($run->start >= $edge) {
                return null;
            }

            if ($run->start > $instant) {
                return $this->period($run);
            }
        }

        return null;
    }

    // ─────────────────────────────── Days ───────────────────────────────

    /**
     * Whether the regular schedule in effect on `$asOf` (default today) opens on
     * this weekday. Exceptions are not considered.
     */
    public function isOpenOn(Weekday|string $weekday, ?DateTimeInterface $asOf = null): bool
    {
        return $this->forWeekday($weekday, $asOf)->isOpen();
    }

    public function isClosedOn(Weekday|string $weekday, ?DateTimeInterface $asOf = null): bool
    {
        return ! $this->isOpenOn($weekday, $asOf);
    }

    /**
     * Whether any range starts on the date, exceptions applied.
     */
    public function isOpenOnDate(LocalDate|DateTimeInterface|string $date): bool
    {
        return $this->forDate($date)->isOpen();
    }

    public function isClosedOnDate(LocalDate|DateTimeInterface|string $date): bool
    {
        return ! $this->isOpenOnDate($date);
    }

    public function forDate(LocalDate|DateTimeInterface|string $date): DayHours
    {
        $local = $this->localDate($date);
        $plan = $this->timeline->plan($local->toEpochDay());

        return new DayHours($local, $local->weekday(), $plan->ranges, $plan->source, $plan->label, $plan->meta);
    }

    /**
     * The regular hours of a weekday in the schedule in effect on `$asOf`.
     */
    public function forWeekday(Weekday|string $weekday, ?DateTimeInterface $asOf = null): DayHours
    {
        $weekday = $weekday instanceof Weekday ? $weekday : Weekday::fromKey($weekday);
        $schedule = $this->definition->scheduleFor($this->asOfDate($asOf));

        if ($schedule === null) {
            return new DayHours(null, $weekday, [], DaySource::None);
        }

        return new DayHours(null, $weekday, $schedule->week->for($weekday), DaySource::Schedule, $schedule->label, $schedule->meta);
    }

    /**
     * The regular week in effect on `$asOf`, from `first_day_of_week`.
     */
    public function forWeek(?DateTimeInterface $asOf = null): WeekHours
    {
        return new WeekHours(array_map(
            fn (Weekday $weekday): DayHours => $this->forWeekday($weekday, $asOf),
            Weekday::ordered(Settings::firstDayOfWeek()),
        ));
    }

    /**
     * The seven actual dates of the week containing `$date`, exceptions applied.
     *
     * @return list<DayHours>
     */
    public function forWeekOf(LocalDate|DateTimeInterface|string $date): array
    {
        $local = $this->localDate($date);
        $offset = ($local->weekday()->iso() - Settings::firstDayOfWeek()->iso() + 7) % 7;
        $start = $local->subDays($offset);

        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $days[] = $this->forDate($start->addDays($i));
        }

        return $days;
    }

    /**
     * Every date from `$from` to `$to` inclusive.
     *
     * @return list<DayHours>
     */
    public function forPeriod(LocalDate|DateTimeInterface|string $from, LocalDate|DateTimeInterface|string $to): array
    {
        $start = $this->localDate($from);
        $count = $start->diffInDays($this->localDate($to)) + 1;
        $this->guardDays($count);

        $days = [];

        for ($i = 0; $i < $count; $i++) {
            $days[] = $this->forDate($start->addDays($i));
        }

        return $days;
    }

    // ─────────────────────────────── Spans ───────────────────────────────

    /**
     * Coalesced opening periods clipped to `[$start, $end)`.
     *
     * @return list<OpeningPeriod>
     */
    public function openingPeriodsBetween(DateTimeInterface $start, DateTimeInterface $end): array
    {
        $from = $start->getTimestamp();
        $until = $end->getTimestamp();

        if ($until <= $from) {
            return [];
        }

        $periods = [];

        foreach ($this->timeline->forward($from, $this->guardSpan($from, $until)) as $run) {
            if ($run->start >= $until) {
                break;
            }

            $periods[] = new OpeningPeriod(
                $this->instant(max($run->start, $from)),
                $this->instant(min($run->end, $until)),
                $run->label,
                $run->source,
                $run->capacity,
            );
        }

        return $periods;
    }

    /**
     * Open for the whole of `[$start, $end)`.
     */
    public function isOpenDuring(DateTimeInterface $start, DateTimeInterface $end): bool
    {
        $from = $start->getTimestamp();
        $until = $end->getTimestamp();

        if ($until <= $from) {
            return false;
        }

        foreach ($this->timeline->forward($from, $this->spanDays($from, $until) + 1) as $run) {
            return $run->start <= $from && ($run->end >= $until || $run->endsAfterScan);
        }

        return false;
    }

    /**
     * Not open at any moment of `[$start, $end)`.
     */
    public function isClosedDuring(DateTimeInterface $start, DateTimeInterface $end): bool
    {
        $from = $start->getTimestamp();
        $until = $end->getTimestamp();

        if ($until <= $from) {
            return true;
        }

        foreach ($this->timeline->forward($from, $this->spanDays($from, $until) + 1) as $run) {
            return $run->start >= $until;
        }

        return true;
    }

    /**
     * Real elapsed open seconds in `[$start, $end)`.
     */
    public function openSecondsBetween(DateTimeInterface $start, DateTimeInterface $end): int
    {
        return array_sum(array_map(
            static fn (OpeningPeriod $period): int => $period->durationSeconds(),
            $this->openingPeriodsBetween($start, $end),
        ));
    }

    public function closedSecondsBetween(DateTimeInterface $start, DateTimeInterface $end): int
    {
        return max(0, $end->getTimestamp() - $start->getTimestamp()) - $this->openSecondsBetween($start, $end);
    }

    public function openDurationBetween(DateTimeInterface $start, DateTimeInterface $end): CarbonInterval
    {
        return CarbonInterval::seconds($this->openSecondsBetween($start, $end))->cascade();
    }

    // ───────────────────────────── Summaries ─────────────────────────────

    /**
     * Weekdays the regular schedule in effect on `$asOf` never opens.
     *
     * @return list<Weekday>
     */
    public function regularClosingDays(?DateTimeInterface $asOf = null): array
    {
        return array_values(array_filter(
            Weekday::ordered(),
            fn (Weekday $weekday): bool => $this->isClosedOn($weekday, $asOf),
        ));
    }

    /**
     * Dates an exception (stored or dynamic) closes completely. Defaults to
     * today … today + 365, shortened to fit `max_query_days`.
     *
     * @return list<LocalDate>
     */
    public function exceptionalClosingDates(LocalDate|DateTimeInterface|string|null $from = null, LocalDate|DateTimeInterface|string|null $to = null): array
    {
        $start = $from === null ? LocalDate::today($this->timezone) : $this->localDate($from);
        $end = $to === null ? $start->addDays(min(365, Settings::maxQueryDays() - 1)) : $this->localDate($to);

        return array_values(array_map(
            static fn (DayHours $day): LocalDate => $day->date ?? $start,
            array_filter($this->exceptionsBetween($start, $end), static fn (DayHours $day): bool => $day->isClosed()),
        ));
    }

    /**
     * Every date in the span whose hours come from an exception or a provider.
     *
     * @return list<DayHours>
     */
    public function exceptionsBetween(LocalDate|DateTimeInterface|string $from, LocalDate|DateTimeInterface|string $to): array
    {
        return array_values(array_filter(
            $this->forPeriod($from, $to),
            static fn (DayHours $day): bool => $day->source === DaySource::Exception || $day->source === DaySource::Dynamic,
        ));
    }

    /**
     * Open around the clock: a base schedule, every schedule covering the whole
     * week, and no exceptions or providers that could close it.
     */
    public function isAlwaysOpen(): bool
    {
        if ($this->alwaysOpen !== null) {
            return $this->alwaysOpen;
        }

        if ($this->providers !== [] || $this->definition->hasExceptions() || $this->definition->data->baseSchedule() === null) {
            return $this->alwaysOpen = false;
        }

        foreach ($this->definition->schedules as $schedule) {
            if (! WeekCoverage::coversWholeWeek($schedule->week)) {
                return $this->alwaysOpen = false;
            }
        }

        return $this->alwaysOpen = true;
    }

    /**
     * No ranges anywhere, and nothing (exception or provider) that could open it.
     */
    public function isAlwaysClosed(): bool
    {
        if ($this->providers !== []) {
            return false;
        }

        foreach ($this->definition->data->schedules as $schedule) {
            if (! $schedule->week->isEmpty()) {
                return false;
            }
        }

        foreach ($this->definition->data->exceptions as $exception) {
            if (! $exception->isClosed()) {
                return false;
            }
        }

        return true;
    }

    /**
     * schema.org `OpeningHoursSpecification` items for the schedule in effect
     * on `$asOf`, plus upcoming one-off exceptions.
     */
    public function toStructuredData(?DateTimeInterface $asOf = null): StructuredData
    {
        return StructuredDataBuilder::build($this->definition, $this->asOfDate($asOf), Settings::upcomingExceptionsDays());
    }

    /**
     * Availability checks and bookable slots on top of these hours.
     */
    public function availability(): Availability
    {
        return new Availability($this);
    }

    // ───────────────────────────── Internals ─────────────────────────────

    /**
     * @internal
     */
    public function instant(int $timestamp): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($timestamp, $this->outputTimezone);
    }

    /**
     * @internal
     */
    public function localDate(LocalDate|DateTimeInterface|string $date): LocalDate
    {
        if ($date instanceof LocalDate) {
            return $date;
        }

        if ($date instanceof DateTimeInterface) {
            return LocalDate::fromDateTime($date, $this->timezone);
        }

        return LocalDate::fromString($date);
    }

    /**
     * @internal
     */
    public function guardDays(int $days): void
    {
        $max = Settings::maxQueryDays();

        if ($days > $max) {
            throw QueryRangeTooLargeException::make($days, $max);
        }
    }

    private function guardSpan(int $from, int $until): int
    {
        $days = $this->spanDays($from, $until);
        $this->guardDays($days);

        return $days + 1;
    }

    private function spanDays(int $from, int $until): int
    {
        return intdiv(max(0, $until - $from) + 86399, 86400);
    }

    private function asOfDate(?DateTimeInterface $asOf): LocalDate
    {
        return $asOf === null ? LocalDate::today($this->timezone) : LocalDate::fromDateTime($asOf, $this->timezone);
    }

    /**
     * @param  iterable<int, Run>  $runs
     */
    private function first(iterable $runs): ?Run
    {
        foreach ($runs as $run) {
            return $run;
        }

        return null;
    }

    private function period(Run $run): OpeningPeriod
    {
        return new OpeningPeriod(
            $this->instant($run->start),
            $this->instant($run->end),
            $run->label,
            $run->source,
            $run->capacity,
            $run->startsBeforeScan,
            $run->endsAfterScan,
        );
    }
}
