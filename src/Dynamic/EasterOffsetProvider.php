<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Dynamic;

use RoundlyConsulting\OpeningHours\Contracts\DynamicExceptionProvider;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\Support\Easter;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;

/**
 * A movable holiday relative to Easter Sunday: Good Friday is `-2`, Easter
 * Monday `+1`, Ascension `+39`, Whit Monday `+50`. Closed unless ranges are given.
 */
final readonly class EasterOffsetProvider implements DynamicExceptionProvider
{
    /** @var list<TimeRange> */
    private array $ranges;

    /**
     * @param  list<TimeRange|string>  $ranges
     */
    public function __construct(
        private int $offsetDays,
        private ?string $label = null,
        array $ranges = [],
    ) {
        $this->ranges = array_map(
            static fn (TimeRange|string $range): TimeRange => $range instanceof TimeRange ? $range : TimeRange::fromString($range),
            $ranges,
        );
    }

    public function exceptionFor(LocalDate $date): ?ExceptionData
    {
        // A large offset moves the holiday into another year than its Easter's.
        $year = $date->year - intdiv($this->offsetDays, 365);

        foreach ([$year, $year + 1, $year - 1] as $year) {
            if (Easter::sunday($year)->addDays($this->offsetDays)->equals($date)) {
                return new ExceptionData(AbsoluteWindow::single($date), $this->ranges, $this->label);
            }
        }

        return null;
    }
}
