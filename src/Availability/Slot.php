<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Availability;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use RoundlyConsulting\OpeningHours\Enums\UnavailableReason;

/**
 * A candidate bookable period `[start, start + duration)`.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class Slot implements Arrayable, JsonSerializable
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

    /**
     * @return array{start: string, end: string, available: bool, remaining_capacity: int, reason: ?string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
