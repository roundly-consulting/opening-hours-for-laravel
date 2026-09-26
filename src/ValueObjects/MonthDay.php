<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\ValueObjects;

use RoundlyConsulting\OpeningHours\Exceptions\InvalidDateException;

/**
 * A year-agnostic month/day (`12-25`). `02-29` is allowed: a yearly window
 * starting on it begins `03-01` in non-leap years, one ending on it ends `02-28`.
 */
final readonly class MonthDay
{
    public function __construct(
        public int $month,
        public int $day,
    ) {
        if (! checkdate($month, $day, 2000)) {
            throw InvalidDateException::invalidMonthDay(sprintf('%02d-%02d', $month, $day));
        }
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/^(\d{2})-(\d{2})$/', $value, $m) !== 1 || ! checkdate((int) $m[1], (int) $m[2], 2000)) {
            throw InvalidDateException::invalidMonthDay($value);
        }

        return new self((int) $m[1], (int) $m[2]);
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[1], (int) $m[2], 2000);
    }

    public function existsIn(int $year): bool
    {
        return checkdate($this->month, $this->day, $year);
    }

    /**
     * The concrete date in `$year`, applying the Feb-29 rule for non-leap years.
     */
    public function toDateIn(int $year, bool $asEnd = false): LocalDate
    {
        if ($this->month === 2 && $this->day === 29 && ! LocalDate::leap($year)) {
            return $asEnd ? new LocalDate($year, 2, 28) : new LocalDate($year, 3, 1);
        }

        return new LocalDate($year, $this->month, $this->day);
    }

    /**
     * Day of year counted in a leap year (1..366).
     */
    public function dayOfLeapYear(): int
    {
        return (new LocalDate(2000, $this->month, $this->day))->toEpochDay() - (new LocalDate(2000, 1, 1))->toEpochDay() + 1;
    }

    public function compare(self $other): int
    {
        return [$this->month, $this->day] <=> [$other->month, $other->day];
    }

    public function equals(self $other): bool
    {
        return $this->month === $other->month && $this->day === $other->day;
    }

    public function toString(): string
    {
        return sprintf('%02d-%02d', $this->month, $this->day);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
