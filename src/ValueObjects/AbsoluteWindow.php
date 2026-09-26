<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\ValueObjects;

use RoundlyConsulting\OpeningHours\Contracts\DateWindow;
use RoundlyConsulting\OpeningHours\Enums\Recurrence;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidDateException;

/**
 * A one-off window of real dates, both bounds inclusive; either may be open.
 */
final readonly class AbsoluteWindow implements DateWindow
{
    public function __construct(
        public ?LocalDate $from = null,
        public ?LocalDate $until = null,
    ) {
        if ($from !== null && $until !== null && $until->isBefore($from)) {
            throw InvalidDateException::inverted($from->toDateString(), $until->toDateString());
        }
    }

    public static function single(LocalDate $date): self
    {
        return new self($date, $date);
    }

    public static function between(?LocalDate $from, ?LocalDate $until): self
    {
        return new self($from, $until);
    }

    public function contains(LocalDate $date): bool
    {
        return ($this->from === null || ! $date->isBefore($this->from))
            && ($this->until === null || ! $date->isAfter($this->until));
    }

    public function spanDays(): ?int
    {
        if ($this->from === null || $this->until === null) {
            return null;
        }

        return $this->from->diffInDays($this->until) + 1;
    }

    public function isUnbounded(): bool
    {
        return $this->from === null && $this->until === null;
    }

    public function overlaps(DateWindow $other): bool
    {
        if ($other instanceof YearlyWindow) {
            return $other->overlaps($this);
        }

        if (! $other instanceof self) {
            return false;
        }

        $startsBeforeOtherEnds = $this->from === null || $other->until === null || ! $this->from->isAfter($other->until);
        $otherStartsBeforeThisEnds = $other->from === null || $this->until === null || ! $other->from->isAfter($this->until);

        return $startsBeforeOtherEnds && $otherStartsBeforeThisEnds;
    }

    public function recurrence(): Recurrence
    {
        return Recurrence::None;
    }

    public function equals(DateWindow $other): bool
    {
        return $other instanceof self
            && $this->from?->toDateString() === $other->from?->toDateString()
            && $this->until?->toDateString() === $other->until?->toDateString();
    }

    public function toArray(): array
    {
        return [
            'from' => $this->from?->toDateString(),
            'until' => $this->until?->toDateString(),
            'recurrence' => Recurrence::None->value,
        ];
    }
}
