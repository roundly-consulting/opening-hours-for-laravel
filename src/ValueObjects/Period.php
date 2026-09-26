<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\ValueObjects;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A concrete half-open interval of instants `[start, end)`.
 */
final readonly class Period
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    public function durationSeconds(): int
    {
        return $this->end->getTimestamp() - $this->start->getTimestamp();
    }

    public function contains(DateTimeInterface $instant): bool
    {
        $at = $instant->getTimestamp();

        return $at >= $this->start->getTimestamp() && $at < $this->end->getTimestamp();
    }

    public function overlaps(self $other): bool
    {
        return $this->start->getTimestamp() < $other->end->getTimestamp()
            && $other->start->getTimestamp() < $this->end->getTimestamp();
    }

    /**
     * @return array{start: string, end: string}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
        ];
    }
}
