<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Availability;

use Illuminate\Support\Collection;

/**
 * `toArray()` and JSON give each slot in `Slot::toArray()` shape, keys kept; any
 * collection method (`map`, `groupBy`, `keyBy`, `chunk`, …) works as usual.
 *
 * @extends Collection<int, Slot>
 */
final class SlotCollection extends Collection
{
    public function available(): self
    {
        return new self(array_values(array_filter($this->items, static fn (Slot $slot): bool => $slot->available)));
    }

    /**
     * @return array<string, list<Slot>> keyed `Y-m-d` (in the slots' timezone)
     */
    public function groupByDate(): array
    {
        $groups = [];

        foreach ($this->items as $slot) {
            $groups[$slot->start->format('Y-m-d')][] = $slot;
        }

        return $groups;
    }
}
