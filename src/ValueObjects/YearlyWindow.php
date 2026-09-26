<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\ValueObjects;

use RoundlyConsulting\OpeningHours\Contracts\DateWindow;
use RoundlyConsulting\OpeningHours\Enums\Recurrence;

/**
 * A window repeating every year, `from` … `until` inclusive. `until < from`
 * wraps the year end (`12-24` … `01-02`).
 */
final readonly class YearlyWindow implements DateWindow
{
    public function __construct(
        public MonthDay $from,
        public MonthDay $until,
    ) {}

    public static function single(MonthDay $day): self
    {
        return new self($day, $day);
    }

    public function wraps(): bool
    {
        return $this->until->compare($this->from) < 0;
    }

    public function contains(LocalDate $date): bool
    {
        $start = $this->from->toDateIn($date->year);
        $end = $this->until->toDateIn($date->year, asEnd: true);

        if ($this->wraps()) {
            return ! $date->isBefore($start) || ! $date->isAfter($end);
        }

        return ! $date->isBefore($start) && ! $date->isAfter($end);
    }

    /**
     * Days covered in a leap year (1..366).
     */
    public function spanDays(): int
    {
        $from = $this->from->dayOfLeapYear();
        $until = $this->until->dayOfLeapYear();

        return $this->wraps() ? 366 - $from + 1 + $until : $until - $from + 1;
    }

    public function overlaps(DateWindow $other): bool
    {
        if ($other instanceof self) {
            foreach ($this->segments() as [$a, $b]) {
                foreach ($other->segments() as [$c, $d]) {
                    if ($a <= $d && $c <= $b) {
                        return true;
                    }
                }
            }

            return false;
        }

        if (! $other instanceof AbsoluteWindow) {
            return false;
        }

        $span = $other->spanDays();

        // Open-ended, or longer than a year: some occurrence always falls inside.
        if ($span === null || $span > 366) {
            return true;
        }

        $date = $other->from;

        for ($i = 0; $i < $span && $date !== null; $i++) {
            if ($this->contains($date)) {
                return true;
            }

            $date = $date->addDays(1);
        }

        return false;
    }

    /**
     * The window as leap-year day-of-year segments (two when it wraps).
     *
     * @return list<array{int, int}>
     */
    private function segments(): array
    {
        $from = $this->from->dayOfLeapYear();
        $until = $this->until->dayOfLeapYear();

        return $this->wraps() ? [[$from, 366], [1, $until]] : [[$from, $until]];
    }

    public function recurrence(): Recurrence
    {
        return Recurrence::Yearly;
    }

    public function equals(DateWindow $other): bool
    {
        return $other instanceof self && $this->from->equals($other->from) && $this->until->equals($other->until);
    }

    public function toArray(): array
    {
        return [
            'from' => $this->from->toString(),
            'until' => $this->until->toString(),
            'recurrence' => Recurrence::Yearly->value,
        ];
    }
}
