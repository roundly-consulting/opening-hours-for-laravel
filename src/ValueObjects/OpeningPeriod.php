<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\ValueObjects;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use RoundlyConsulting\OpeningHours\Enums\DaySource;

/**
 * A concrete opening period. When `startsBeforeScan` / `endsAfterScan` is set,
 * the period continues past the search window and the reported edge is the
 * window edge, not a real opening or closing.
 */
final readonly class OpeningPeriod
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public ?string $label = null,
        public DaySource $source = DaySource::Schedule,
        public ?int $capacity = null,
        public bool $startsBeforeScan = false,
        public bool $endsAfterScan = false,
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

    public function toPeriod(): Period
    {
        return new Period($this->start, $this->end);
    }

    /**
     * @return array{start: string, end: string, label: ?string, start_unbounded: bool, end_unbounded: bool}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'label' => $this->label,
            'start_unbounded' => $this->startsBeforeScan,
            'end_unbounded' => $this->endsAfterScan,
        ];
    }
}
