<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Engine;

use RoundlyConsulting\OpeningHours\Enums\DaySource;

/**
 * A coalesced opening run in Unix seconds, mutable while it is being merged.
 *
 * @internal
 */
final class Run
{
    private bool $labelAgrees = true;

    private bool $capacityAgrees = true;

    public function __construct(
        public int $start,
        public int $end,
        public ?string $label,
        public DaySource $source,
        public ?int $capacity,
        public bool $startsBeforeScan = false,
        public bool $endsAfterScan = false,
    ) {}

    public static function from(RawPeriod $period): self
    {
        return new self($period->start, $period->end, $period->range->label, $period->source, $period->range->capacity);
    }

    /**
     * Merge a touching or overlapping period: the label survives only if every
     * part shares it, likewise the capacity.
     */
    public function absorb(RawPeriod $period): void
    {
        $this->end = max($this->end, $period->end);

        if ($this->labelAgrees && $this->label !== $period->range->label) {
            $this->labelAgrees = false;
            $this->label = null;
        }

        if ($this->capacityAgrees && $this->capacity !== $period->range->capacity) {
            $this->capacityAgrees = false;
            $this->capacity = null;
        }
    }
}
