<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Availability;

use RoundlyConsulting\OpeningHours\Enums\UnavailableReason;

/**
 * The answer to "can `[start, end)` be booked?" — and, when not, why.
 */
final readonly class AvailabilityResult
{
    public function __construct(
        public bool $available,
        public ?UnavailableReason $reason = null,
        public ?BusyPeriod $conflict = null,
        public int $remainingCapacity = 0,
    ) {}

    public static function ok(int $remainingCapacity): self
    {
        return new self(true, null, null, $remainingCapacity);
    }

    public static function unavailable(UnavailableReason $reason, ?BusyPeriod $conflict = null, int $remainingCapacity = 0): self
    {
        return new self(false, $reason, $conflict, $remainingCapacity);
    }

    public function message(?string $locale = null): ?string
    {
        return $this->reason?->message($locale);
    }
}
