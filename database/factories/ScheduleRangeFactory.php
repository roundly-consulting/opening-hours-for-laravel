<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\Models\ScheduleRange;
use RoundlyConsulting\OpeningHours\ValueObjects\Time;

/**
 * @extends Factory<ScheduleRange>
 */
final class ScheduleRangeFactory extends Factory
{
    protected $model = ScheduleRange::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'schedule_id' => ScheduleFactory::new(),
            'weekday' => Weekday::Monday,
            'start_minute' => 9 * 60,
            'end_minute' => 17 * 60,
            'capacity' => null,
            'label' => null,
            'meta' => null,
            'position' => 0,
        ];
    }

    public function on(Weekday $weekday): self
    {
        return $this->state(fn (): array => ['weekday' => $weekday]);
    }

    public function between(string $start, string $end): self
    {
        return $this->state(fn (): array => [
            'start_minute' => Time::fromString($start)->minutes,
            'end_minute' => Time::fromString($end)->minutes,
        ]);
    }
}
