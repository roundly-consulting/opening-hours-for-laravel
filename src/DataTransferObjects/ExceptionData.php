<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\DataTransferObjects;

use RoundlyConsulting\OpeningHours\Contracts\DateWindow;
use RoundlyConsulting\OpeningHours\Enums\Recurrence;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;

/**
 * An exception rule: on every date of its window the given ranges replace the
 * schedule (no ranges = closed).
 */
final readonly class ExceptionData
{
    /**
     * @param  list<TimeRange>  $ranges
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        public DateWindow $window,
        public array $ranges = [],
        public ?string $label = null,
        public ?array $meta = null,
        public ?int $id = null,
    ) {}

    public function isClosed(): bool
    {
        return $this->ranges === [];
    }

    public function recurrence(): Recurrence
    {
        return $this->window->recurrence();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $withMeta = true): array
    {
        $window = $this->window->toArray();

        $array = [
            'id' => $this->id,
            'from' => $window['from'],
            'until' => $window['until'],
            'recurrence' => $window['recurrence'],
            'ranges' => array_map(static fn (TimeRange $range): array => $range->toArray($withMeta), $this->ranges),
            'label' => $this->label,
        ];

        if ($withMeta) {
            $array['meta'] = $this->meta;
        }

        return $array;
    }
}
