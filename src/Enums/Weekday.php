<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Enums;

use DateTimeInterface;
use RoundlyConsulting\Enums\Helpers;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOpeningHoursException;
use RoundlyConsulting\OpeningHours\Validation\Violation;

/**
 * A day of the week in ISO order (Monday = 1 … Sunday = 7). Stored in the
 * database as its ISO number.
 */
enum Weekday: string
{
    use Helpers;

    case Monday = 'monday';
    case Tuesday = 'tuesday';
    case Wednesday = 'wednesday';
    case Thursday = 'thursday';
    case Friday = 'friday';
    case Saturday = 'saturday';
    case Sunday = 'sunday';

    public static function fromIso(int $iso): self
    {
        return match ($iso) {
            1 => self::Monday,
            2 => self::Tuesday,
            3 => self::Wednesday,
            4 => self::Thursday,
            5 => self::Friday,
            6 => self::Saturday,
            7 => self::Sunday,
            default => throw InvalidOpeningHoursException::fromViolation(
                new Violation(ViolationCode::UnknownWeekday, '', ['value' => (string) $iso]),
            ),
        };
    }

    /**
     * Accepts `monday`, `Monday`, `mon`, `MON` or the ISO number `1`–`7`
     * (as int or numeric string). `0` is deliberately not Sunday.
     */
    public static function fromKey(string|int $key): self
    {
        $weekday = self::tryFromKey($key);

        if ($weekday === null) {
            throw InvalidOpeningHoursException::fromViolation(
                new Violation(ViolationCode::UnknownWeekday, '', ['value' => (string) $key]),
            );
        }

        return $weekday;
    }

    public static function tryFromKey(string|int $key): ?self
    {
        if (is_int($key) || ctype_digit($key)) {
            $iso = (int) $key;

            return $iso >= 1 && $iso <= 7 ? self::fromIso($iso) : null;
        }

        $normalised = strtolower(trim($key));

        foreach (self::cases() as $case) {
            if ($case->value === $normalised || $case->short() === $normalised) {
                return $case;
            }
        }

        return null;
    }

    /**
     * The weekday of the date as the given object reads it (convert it to the
     * calendar timezone first when that matters).
     */
    public static function fromDate(DateTimeInterface $date): self
    {
        return self::fromIso((int) $date->format('N'));
    }

    public function iso(): int
    {
        return match ($this) {
            self::Monday => 1,
            self::Tuesday => 2,
            self::Wednesday => 3,
            self::Thursday => 4,
            self::Friday => 5,
            self::Saturday => 6,
            self::Sunday => 7,
        };
    }

    public function short(): string
    {
        return substr($this->value, 0, 3);
    }

    public function next(): self
    {
        return self::fromIso($this->iso() % 7 + 1);
    }

    public function previous(): self
    {
        return self::fromIso(($this->iso() + 5) % 7 + 1);
    }

    public function isWeekend(): bool
    {
        return $this === self::Saturday || $this === self::Sunday;
    }

    public function translated(?string $locale = null, bool $short = false): string
    {
        $key = $short ? 'opening-hours::weekdays.short.'.$this->value : 'opening-hours::weekdays.long.'.$this->value;

        return (string) trans($key, [], $locale);
    }

    /**
     * All seven weekdays, starting from `$first` (Monday when null).
     *
     * @return list<self>
     */
    public static function ordered(?self $first = null): array
    {
        $day = $first ?? self::Monday;
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $days[] = $day;
            $day = $day->next();
        }

        return $days;
    }
}
