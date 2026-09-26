<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\DataTransferObjects;

use RoundlyConsulting\OpeningHours\Contracts\DateWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;

/**
 * A weekly schedule. Without a window it is the calendar's base schedule; with
 * one it is seasonal and competes by `priority`.
 */
final readonly class ScheduleData
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        public WeekData $week = new WeekData,
        public ?DateWindow $window = null,
        public int $priority = 0,
        public ?string $label = null,
        public ?array $meta = null,
        public ?int $id = null,
    ) {}

    public function isBase(): bool
    {
        return $this->window === null
            || ($this->window instanceof AbsoluteWindow && $this->window->isUnbounded());
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function with(
        ?WeekData $week = null,
        ?DateWindow $window = null,
        ?int $priority = null,
        ?string $label = null,
        ?array $meta = null,
        ?int $id = null,
    ): self {
        return new self(
            week: $week ?? $this->week,
            window: $window ?? $this->window,
            priority: $priority ?? $this->priority,
            label: $label ?? $this->label,
            meta: $meta ?? $this->meta,
            id: $id ?? $this->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $withMeta = true): array
    {
        $array = [
            'id' => $this->id,
            'label' => $this->label,
            'priority' => $this->priority,
            'window' => $this->isBase() ? null : $this->window?->toArray(),
            'week' => $this->week->toArray($withMeta),
        ];

        if ($withMeta) {
            $array['meta'] = $this->meta;
        }

        return $array;
    }
}
