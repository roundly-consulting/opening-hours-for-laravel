<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Availability;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use RoundlyConsulting\OpeningHours\Availability\Providers\ArrayBusyPeriodProvider;
use RoundlyConsulting\OpeningHours\Availability\Providers\ClosureBusyPeriodProvider;
use RoundlyConsulting\OpeningHours\Availability\Providers\NullBusyPeriodProvider;
use RoundlyConsulting\OpeningHours\Contracts\BusyPeriodProvider;
use RoundlyConsulting\OpeningHours\Enums\UnavailableReason;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidSlotQueryException;
use RoundlyConsulting\OpeningHours\Exceptions\TooManyBusyPeriodsException;
use RoundlyConsulting\OpeningHours\OpeningHours;
use RoundlyConsulting\OpeningHours\Support\Clock;
use RoundlyConsulting\OpeningHours\Support\Limits;
use RoundlyConsulting\OpeningHours\Support\Settings;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\OpeningHours\ValueObjects\Period;

/**
 * Availability on top of opening hours: can `[start, end)` be booked, and which
 * slots are free — against busy periods from any source, with parallel
 * capacity, buffers, minimum notice and a booking horizon. Immutable: every
 * setter returns a changed copy.
 *
 * This is a read. A host persisting a booking must re-check inside its own lock
 * or unique constraint (time-of-check vs time-of-use).
 */
final class Availability
{
    private BusyPeriodProvider $provider;

    private ?int $capacity = null;

    private int $before = 0;

    private int $after = 0;

    private bool $withinOpeningHours = true;

    private int $minNotice = 0;

    private ?int $horizonDays = null;

    private ?int $at = null;

    public function __construct(private readonly OpeningHours $hours)
    {
        $this->provider = new NullBusyPeriodProvider;
    }

    /**
     * @param  BusyPeriodProvider|iterable<BusyPeriod>|(Closure(CarbonImmutable, CarbonImmutable): iterable<BusyPeriod>)  $busy
     */
    public function withBusyPeriods(BusyPeriodProvider|iterable|Closure $busy): self
    {
        $clone = clone $this;
        $clone->provider = match (true) {
            $busy instanceof BusyPeriodProvider => $busy,
            $busy instanceof Closure => new ClosureBusyPeriodProvider($busy),
            default => new ArrayBusyPeriodProvider($busy),
        };

        return $clone;
    }

    /**
     * Default parallel capacity; a range's own capacity overrides it (fallback 1).
     */
    public function capacity(int $capacity): self
    {
        self::atLeast('capacity', $capacity, 1);
        $clone = clone $this;
        $clone->capacity = $capacity;

        return $clone;
    }

    /**
     * Minutes blocked before and after each booking. With `withinOpeningHours`
     * the buffers must also fall inside opening hours.
     */
    public function buffers(int $before = 0, int $after = 0, bool $withinOpeningHours = true): self
    {
        self::atLeast('before', $before, 0);
        self::atLeast('after', $after, 0);
        $clone = clone $this;
        $clone->before = $before;
        $clone->after = $after;
        $clone->withinOpeningHours = $withinOpeningHours;

        return $clone;
    }

    /**
     * Earliest start: now + this many real minutes.
     */
    public function minNotice(int $minutes): self
    {
        self::atLeast('minNotice', $minutes, 0);
        $clone = clone $this;
        $clone->minNotice = $minutes;

        return $clone;
    }

    /**
     * Latest start: now + this many calendar days (wall clock, in the calendar
     * timezone), inclusive; null = unlimited.
     */
    public function horizon(?int $days): self
    {
        if ($days !== null) {
            self::atLeast('horizon', $days, 0);
        }

        $clone = clone $this;
        $clone->horizonDays = $days;

        return $clone;
    }

    /**
     * Evaluate as of this instant instead of now.
     */
    public function at(DateTimeInterface $at): self
    {
        $clone = clone $this;
        $clone->at = $at->getTimestamp();

        return $clone;
    }

    public function check(DateTimeInterface $start, DateTimeInterface $end, int $weight = 1): AvailabilityResult
    {
        self::atLeast('weight', $weight, 1);
        $from = $start->getTimestamp();
        $until = $end->getTimestamp();
        $now = $this->now();

        if ($until <= $from) {
            return AvailabilityResult::unavailable(UnavailableReason::InvalidRange);
        }

        if ($from < $now) {
            return AvailabilityResult::unavailable(UnavailableReason::InPast);
        }

        if ($from < $now + $this->minNotice * 60) {
            return AvailabilityResult::unavailable(UnavailableReason::TooSoon);
        }

        $horizonEnd = $this->horizonEnd($now);

        if ($horizonEnd !== null && $from > $horizonEnd) {
            return AvailabilityResult::unavailable(UnavailableReason::TooFar);
        }

        $occupiedFrom = $from - $this->before * 60;
        $occupiedUntil = $until + $this->after * 60;
        [$hoursFrom, $hoursUntil] = $this->withinOpeningHours ? [$occupiedFrom, $occupiedUntil] : [$from, $until];

        if (! $this->hours->isOpenDuring($this->hours->instant($hoursFrom), $this->hours->instant($hoursUntil))) {
            return AvailabilityResult::unavailable(UnavailableReason::Closed);
        }

        $busy = $this->busy($occupiedFrom, $occupiedUntil);
        $remaining = $this->timeline($busy, $occupiedFrom, $occupiedUntil)->minRemaining($occupiedFrom, $occupiedUntil);

        if ($remaining < $weight) {
            $conflict = null;

            foreach ($busy as $period) {
                if ($period->overlaps($occupiedFrom, $occupiedUntil)) {
                    $conflict = $period;
                    break;
                }
            }

            return AvailabilityResult::unavailable(UnavailableReason::Busy, $conflict, max(0, $remaining));
        }

        return AvailabilityResult::ok($remaining);
    }

    public function isAvailable(DateTimeInterface $start, DateTimeInterface $end, int $weight = 1): bool
    {
        return $this->check($start, $end, $weight)->available;
    }

    /**
     * Slots from `$from` to `$to`: dates are whole local days (inclusive),
     * instants are taken as-is (`$to` exclusive).
     */
    public function slots(LocalDate|DateTimeInterface|string $from, LocalDate|DateTimeInterface|string $to): SlotQuery
    {
        return new SlotQuery($this, $this->startBound($from), $this->endBound($to));
    }

    /**
     * The first free slot after `$after` (or now, whichever is later), scanning
     * 7-day chunks up to `min(horizon, search_days)`.
     */
    public function nextAvailableSlot(int $duration, ?DateTimeInterface $after = null, ?int $step = null, int $weight = 1): ?Slot
    {
        $clock = $this->hours->timeline()->clock;
        // Nothing before now is bookable: never spend the search window there.
        $start = max($after?->getTimestamp() ?? PHP_INT_MIN, $this->now());
        $limit = $this->horizonDays === null ? Settings::searchDays() : min($this->horizonDays, Settings::searchDays());
        $day = $clock->localEpochDay($start);

        for ($scanned = 0; $scanned <= $limit; $scanned += 7) {
            $chunkStart = $scanned === 0 ? $start : $clock->boundaryAt($day + $scanned, 0);
            $chunkEnd = $clock->boundaryAt($day + $scanned + 7, 0);
            $query = new SlotQuery($this, $chunkStart, $chunkEnd);
            $slot = $query->duration($duration)->step($step)->weight($weight)->first();

            if ($slot !== null) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Maximal open stretches with at least `$weight` free capacity.
     *
     * @return list<Period>
     */
    public function freePeriods(LocalDate|DateTimeInterface|string $from, LocalDate|DateTimeInterface|string $to, int $weight = 1): array
    {
        self::atLeast('weight', $weight, 1);
        $start = $this->startBound($from);
        $end = $this->endBound($to);
        $this->guardSpan($start, $end);

        $stretches = $this->timeline($this->busy($start, $end), $start, $end, outside: 0)->freeStretches($weight);

        return array_map(
            fn (array $stretch): Period => new Period($this->hours->instant($stretch[0]), $this->hours->instant($stretch[1])),
            $stretches,
        );
    }

    /**
     * @internal the slot generator (§7.6): starts are constrained by the window,
     * notice and horizon; bodies and buffers by the hours and capacity.
     */
    public function generate(int $from, int $until, int $duration, int $step, int $alignTo, int $anchor, int $weight, bool $includeUnavailable, int $limit): SlotCollection
    {
        $this->guardSpan($from, $until);
        $now = $this->now();
        $startMin = max($from, $now + $this->minNotice * 60);
        $startMax = $this->horizonEnd($now) ?? PHP_INT_MAX;
        $lastStart = min($until, $startMax === PHP_INT_MAX ? $until : $startMax + 1);
        $slots = [];

        if ($lastStart <= $startMin) {
            return new SlotCollection;
        }

        $beforeIn = $this->withinOpeningHours ? $this->before * 60 : 0;
        $afterIn = $this->withinOpeningHours ? $this->after * 60 : 0;
        $windowFrom = $startMin - $this->before * 60 - 86400;
        $windowUntil = $lastStart + ($duration + $this->after) * 60 + 86400;
        $capacity = $this->timeline($this->busy($windowFrom, $windowUntil), $windowFrom, $windowUntil);
        $clock = $this->hours->timeline()->clock;
        // Reach past the last start by the slot body and after-buffer: a run cut at
        // the scan edge would otherwise reject slots that end days later (rentals).
        $days = intdiv($lastStart - $startMin + ($duration + $this->after) * 60 + 86399, 86400) + 1;

        foreach ($this->hours->timeline()->forward($startMin, $days) as $run) {
            if ($run->start >= $lastStart) {
                break;
            }

            $candidate = SlotGrid::ceil(max($run->start + $beforeIn, $startMin), $alignTo, $anchor, $clock);

            while ($candidate < $until && $candidate <= $startMax && $candidate + $duration * 60 + $afterIn <= $run->end) {
                $remaining = $capacity->minRemaining($candidate - $this->before * 60, $candidate + ($duration + $this->after) * 60);
                $available = $remaining >= $weight;

                if ($available || $includeUnavailable) {
                    $slots[] = new Slot(
                        $this->hours->instant($candidate),
                        $this->hours->instant($candidate + $duration * 60),
                        $available,
                        max(0, $remaining),
                        $available ? null : UnavailableReason::Busy,
                    );

                    if (count($slots) >= $limit) {
                        return new SlotCollection($slots);
                    }
                }

                $candidate = SlotGrid::ceil($candidate + $step * 60, $alignTo, $anchor, $clock);
            }
        }

        return new SlotCollection($slots);
    }

    private function now(): int
    {
        return $this->at ?? Clock::now()->getTimestamp();
    }

    private function horizonEnd(int $now): ?int
    {
        if ($this->horizonDays === null) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp($now, $this->hours->timezone())->addDays($this->horizonDays)->getTimestamp();
    }

    /**
     * @return list<BusyPeriod>
     */
    private function busy(int $from, int $until): array
    {
        $limit = Limits::busyPeriods();
        $periods = [];

        foreach ($this->provider->busyPeriodsBetween($this->hours->instant($from), $this->hours->instant($until)) as $period) {
            if (! $period->overlaps($from, $until)) {
                continue;
            }

            $periods[] = $period;

            if (count($periods) > $limit) {
                throw TooManyBusyPeriodsException::make($limit);
            }
        }

        usort($periods, static fn (BusyPeriod $a, BusyPeriod $b): int => $a->start->getTimestamp() <=> $b->start->getTimestamp());

        return $periods;
    }

    /**
     * @param  list<BusyPeriod>  $busy
     */
    private function timeline(array $busy, int $from, int $until, ?int $outside = null): CapacityTimeline
    {
        $default = $this->capacity ?? 1;

        return new CapacityTimeline(
            $this->hours->timeline(),
            $busy,
            $from,
            $until,
            $default,
            $outside ?? ($this->withinOpeningHours ? 0 : $default),
        );
    }

    private function startBound(LocalDate|DateTimeInterface|string $value): int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        return $this->hours->timeline()->clock->boundary($this->hours->localDate($value), 0);
    }

    private function endBound(LocalDate|DateTimeInterface|string $value): int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        return $this->hours->timeline()->clock->boundary($this->hours->localDate($value)->addDays(1), 0);
    }

    private function guardSpan(int $from, int $until): void
    {
        $this->hours->guardDays(intdiv(max(0, $until - $from) + 86399, 86400));
    }

    private static function atLeast(string $setting, int $value, int $min): void
    {
        if ($value < $min) {
            throw InvalidSlotQueryException::outOfRange($setting, $value, $min);
        }
    }
}
