<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Contracts;

use RoundlyConsulting\OpeningHours\Enums\Recurrence;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;

/**
 * A span of local dates — one-off (`AbsoluteWindow`) or repeating every year
 * (`YearlyWindow`).
 */
interface DateWindow
{
    public function contains(LocalDate $date): bool;

    /**
     * Number of days covered, or null when a bound is open.
     */
    public function spanDays(): ?int;

    public function overlaps(self $other): bool;

    public function recurrence(): Recurrence;

    /**
     * Same bounds and recurrence.
     */
    public function equals(self $other): bool;

    /**
     * @return array{from: ?string, until: ?string, recurrence: string}
     */
    public function toArray(): array;
}
