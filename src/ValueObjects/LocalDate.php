<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\ValueObjects;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidDateException;
use RoundlyConsulting\OpeningHours\Support\Clock;

/**
 * A timezone-free calendar date. Arithmetic is pure integer day counting, so
 * no DST transition can ever shift it.
 */
final readonly class LocalDate
{
    public function __construct(
        public int $year,
        public int $month,
        public int $day,
    ) {
        if (! checkdate($month, $day, $year)) {
            throw InvalidDateException::invalidDate(sprintf('%04d-%02d-%02d', $year, $month, $day));
        }
    }

    /**
     * Strict `Y-m-d`, a real date, years 1900–2200.
     */
    public static function fromString(string $value): self
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            throw InvalidDateException::invalidDate($value);
        }

        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if ($year < 1900 || $year > 2200 || ! checkdate($month, $day, $year)) {
            throw InvalidDateException::invalidDate($value);
        }

        return new self($year, $month, $day);
    }

    public static function isValid(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return (int) $m[1] >= 1900 && (int) $m[1] <= 2200 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * The date the instant falls on in `$timezone`.
     */
    public static function fromDateTime(DateTimeInterface $dateTime, DateTimeZone $timezone): self
    {
        $local = DateTimeImmutable::createFromInterface($dateTime)->setTimezone($timezone);

        return new self((int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j'));
    }

    public static function today(DateTimeZone $timezone): self
    {
        return self::fromDateTime(Clock::now(), $timezone);
    }

    /**
     * Days since 1970-01-01 (negative before).
     */
    public static function fromEpochDay(int $days): self
    {
        $z = $days + 719468;
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $day = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $month = $mp < 10 ? $mp + 3 : $mp - 9;
        $year = $yoe + $era * 400 + ($month <= 2 ? 1 : 0);

        return new self($year, $month, $day);
    }

    public function toEpochDay(): int
    {
        $year = $this->month <= 2 ? $this->year - 1 : $this->year;
        $era = intdiv($year >= 0 ? $year : $year - 399, 400);
        $yoe = $year - $era * 400;
        $doy = intdiv(153 * ($this->month + ($this->month > 2 ? -3 : 9)) + 2, 5) + $this->day - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }

    public function addDays(int $days): self
    {
        return $days === 0 ? $this : self::fromEpochDay($this->toEpochDay() + $days);
    }

    public function subDays(int $days): self
    {
        return $this->addDays(-$days);
    }

    public function weekday(): Weekday
    {
        $iso = (($this->toEpochDay() + 3) % 7 + 7) % 7 + 1;

        return Weekday::fromIso($iso);
    }

    public function monthDay(): MonthDay
    {
        return new MonthDay($this->month, $this->day);
    }

    /**
     * Signed number of days from this date to `$other`.
     */
    public function diffInDays(self $other): int
    {
        return $other->toEpochDay() - $this->toEpochDay();
    }

    public function compare(self $other): int
    {
        return [$this->year, $this->month, $this->day] <=> [$other->year, $other->month, $other->day];
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function isBefore(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function isAfter(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function isLeapYear(): bool
    {
        return self::leap($this->year);
    }

    public static function leap(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    public function toDateString(): string
    {
        return sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);
    }

    public function __toString(): string
    {
        return $this->toDateString();
    }
}
