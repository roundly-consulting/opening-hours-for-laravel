<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Availability;

use Carbon\CarbonImmutable;
use RoundlyConsulting\OpeningHours\Enums\UnavailableReason;

/**
 * A candidate bookable period `[start, start + duration)`.
 */
final readonly class Slot
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public bool $available,
        public int $remainingCapacity,
        public ?UnavailableReason $reason = null,
    ) {}

    /**
     * @return array{start: string, end: string, available: bool, remaining_capacity: int, reason: ?string}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'available' => $this->available,
            'remaining_capacity' => $this->remainingCapacity,
            'reason' => $this->reason?->value,
        ];
    }
}
