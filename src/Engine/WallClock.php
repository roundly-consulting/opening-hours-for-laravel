<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Engine;

use DateTimeImmutable;
use DateTimeZone;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;

/**
 * Converts between wall-clock boundaries and instants with one explicit rule:
 * a boundary `(date, minute)` resolves to the FIRST instant at which the local
 * wall clock reads a datetime at or after it. Built from the zone's transition
 * table — never from PHP's implicit resolution, which picks different
 * occurrences of an ambiguous time in different zones.
 *
 * @internal
 */
final class WallClock
{
    private const int MARGIN = 3 * 86400;

    /** @var array<string, self> */
    private static array $instances = [];

    /** @var array<int, list<array{int, int}>> year => [[transition instant, offset seconds], …] */
    private array $pieces = [];

    private function __construct(private readonly DateTimeZone $timezone) {}

    public static function for(DateTimeZone $timezone): self
    {
        return self::$instances[$timezone->getName()] ??= new self($timezone);
    }

    public function timezone(): DateTimeZone
    {
        return $this->timezone;
    }

    /**
     * The instant of wall-clock `(date, minute)`; `minute` 0..1440.
     */
    public function boundary(LocalDate $date, int $minute): int
    {
        return $this->boundaryAt($date->toEpochDay(), $minute);
    }

    public function boundaryAt(int $epochDay, int $minute): int
    {
        $target = $epochDay * 86400 + $minute * 60;
        $pieces = $this->piecesFor($this->yearOf($target));
        $count = count($pieces);

        for ($i = 0; $i < $count; $i++) {
            [$start, $offset] = $pieces[$i];
            $next = $i + 1 < $count ? $pieces[$i + 1][0] : null;

            if ($next === null || $next + $offset > $target) {
                return max($start, $target - $offset);
            }
        }

        // @codeCoverageIgnoreStart
        return $target;
        // @codeCoverageIgnoreEnd
    }

    public function offsetAt(int $instant): int
    {
        $offset = 0;

        foreach ($this->piecesFor($this->yearOf($instant)) as [$start, $pieceOffset]) {
            if ($start > $instant) {
                break;
            }

            $offset = $pieceOffset;
        }

        return $offset;
    }

    /**
     * The first offset change strictly after the instant, looked up through the
     * following year; null when the zone has none there.
     */
    public function nextTransitionAfter(int $instant): ?int
    {
        $year = $this->yearOf($instant);

        foreach ([$year, $year + 1] as $candidate) {
            // A year's first piece is the state at its scan start, not a transition.
            foreach (array_slice($this->piecesFor($candidate), 1) as [$start]) {
                if ($start > $instant) {
                    return $start;
                }
            }
        }

        return null;
    }

    public function localEpochDay(int $instant): int
    {
        $local = $instant + $this->offsetAt($instant);

        return intdiv($local - self::floorMod($local, 86400), 86400);
    }

    public function localDate(int $instant): LocalDate
    {
        return LocalDate::fromEpochDay($this->localEpochDay($instant));
    }

    /**
     * Seconds since local midnight.
     */
    public function localSecondOfDay(int $instant): int
    {
        return self::floorMod($instant + $this->offsetAt($instant), 86400);
    }

    public function localMinute(int $instant): int
    {
        return intdiv($this->localSecondOfDay($instant), 60);
    }

    private function yearOf(int $instant): int
    {
        return (int) gmdate('Y', $instant);
    }

    /**
     * @return list<array{int, int}>
     */
    private function piecesFor(int $year): array
    {
        if (isset($this->pieces[$year])) {
            return $this->pieces[$year];
        }

        $begin = gmmktime(0, 0, 0, 1, 1, $year) - self::MARGIN;
        $end = gmmktime(0, 0, 0, 1, 1, $year + 1) + self::MARGIN;
        $pieces = [];

        foreach ($this->timezone->getTransitions($begin, $end) ?: [] as $transition) {
            $pieces[] = [(int) $transition['ts'], (int) $transition['offset']];
        }

        if ($pieces === []) {
            // @codeCoverageIgnoreStart
            $pieces[] = [$begin, $this->timezone->getOffset(new DateTimeImmutable('@'.$begin))];
            // @codeCoverageIgnoreEnd
        }

        return $this->pieces[$year] = $pieces;
    }

    private static function floorMod(int $value, int $divisor): int
    {
        return (($value % $divisor) + $divisor) % $divisor;
    }
}
