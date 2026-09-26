<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Availability;

use RoundlyConsulting\OpeningHours\Exceptions\InvalidSlotQueryException;
use RoundlyConsulting\OpeningHours\Support\Limits;

/**
 * A slot search: `duration` (required), `step` (default: duration), grid
 * alignment (default: step), weight, and how many to return.
 */
final class SlotQuery
{
    private ?int $duration = null;

    private ?int $step = null;

    private ?int $alignTo = null;

    private int $anchor = 0;

    private int $weight = 1;

    private bool $includeUnavailable = false;

    private ?int $limit = null;

    public function __construct(
        private readonly Availability $availability,
        private readonly int $from,
        private readonly int $until,
    ) {}

    public function duration(int $minutes): self
    {
        $this->assert('duration', $minutes, 1);
        $this->duration = $minutes;

        return $this;
    }

    public function step(?int $minutes): self
    {
        if ($minutes !== null) {
            $this->assert('step', $minutes, 1);
        }

        $this->step = $minutes;

        return $this;
    }

    /**
     * Start slots on `anchor + k · minutes` past local midnight.
     */
    public function alignTo(?int $minutes, int $anchor = 0): self
    {
        if ($minutes !== null) {
            $this->assert('alignTo', $minutes, 1);
        }

        $this->assert('anchor', $anchor, 0);
        $this->alignTo = $minutes;
        $this->anchor = $anchor;

        return $this;
    }

    public function weight(int $weight): self
    {
        $this->assert('weight', $weight, 1);
        $this->weight = $weight;

        return $this;
    }

    /**
     * Also return slots that fail only on capacity (reason `busy`).
     */
    public function includeUnavailable(bool $include = true): self
    {
        $this->includeUnavailable = $include;

        return $this;
    }

    public function limit(int $limit): self
    {
        $max = Limits::slots();

        if ($limit < 1 || $limit > $max) {
            throw InvalidSlotQueryException::outOfRange('limit', $limit, 1, $max);
        }

        $this->limit = $limit;

        return $this;
    }

    public function get(): SlotCollection
    {
        if ($this->duration === null) {
            throw InvalidSlotQueryException::missingDuration();
        }

        $step = $this->step ?? $this->duration;

        return $this->availability->generate(
            $this->from,
            $this->until,
            $this->duration,
            $step,
            $this->alignTo ?? $step,
            $this->anchor,
            $this->weight,
            $this->includeUnavailable,
            $this->limit ?? Limits::slots(),
        );
    }

    public function first(): ?Slot
    {
        $previous = $this->limit;
        $this->limit = 1;
        $slots = $this->get();
        $this->limit = $previous;

        return $slots->first();
    }

    private function assert(string $setting, int $value, int $min): void
    {
        if ($value < $min) {
            throw InvalidSlotQueryException::outOfRange($setting, $value, $min);
        }
    }
}
