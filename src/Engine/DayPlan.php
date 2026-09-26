<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Engine;

use RoundlyConsulting\OpeningHours\Enums\DaySource;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;

/**
 * The ranges that apply on one local date, and where they came from.
 *
 * @internal
 */
final readonly class DayPlan
{
    /**
     * @param  list<TimeRange>  $ranges
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        public array $ranges,
        public DaySource $source,
        public ?string $label = null,
        public ?array $meta = null,
    ) {}
}
