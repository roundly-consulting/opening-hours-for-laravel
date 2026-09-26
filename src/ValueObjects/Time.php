<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\ValueObjects;

use RoundlyConsulting\OpeningHours\Exceptions\InvalidTimeException;

/**
 * A wall-clock time of day with minute precision, `00:00` … `24:00`. `24:00`
 * only ever means "the end of the day" and is valid as a range end only.
 */
final readonly class Time
{
    public const int END_OF_DAY = 1440;

    public function __construct(public int $minutes)
    {
        if ($minutes < 0 || $minutes > self::END_OF_DAY) {
            throw InvalidTimeException::outOfRange($minutes);
        }
    }

    public static function fromString(string $value): self
    {
        if ($value === '24:00') {
            return new self(self::END_OF_DAY);
        }

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $matches) !== 1) {
            throw InvalidTimeException::invalidFormat($value);
        }

        return new self((int) $matches[1] * 60 + (int) $matches[2]);
    }

    public static function fromMinutes(int $minutes): self
    {
        return new self($minutes);
    }

    public static function isValid(string $value): bool
    {
        return $value === '24:00' || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value) === 1;
    }

    public function hours(): int
    {
        return intdiv($this->minutes, 60);
    }

    public function minute(): int
    {
        return $this->minutes % 60;
    }

    public function format(): string
    {
        return sprintf('%02d:%02d', $this->hours(), $this->minute());
    }

    public function isMidnightEnd(): bool
    {
        return $this->minutes === self::END_OF_DAY;
    }

    public function compare(self $other): int
    {
        return $this->minutes <=> $other->minutes;
    }

    public function equals(self $other): bool
    {
        return $this->minutes === $other->minutes;
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
