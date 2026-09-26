<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\OpeningHours\Models\ExceptionRange;
use RoundlyConsulting\OpeningHours\ValueObjects\Time;

/**
 * @extends Factory<ExceptionRange>
 */
final class ExceptionRangeFactory extends Factory
{
    protected $model = ExceptionRange::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exception_rule_id' => ExceptionRuleFactory::new(),
            'start_minute' => 10 * 60,
            'end_minute' => 12 * 60,
            'capacity' => null,
            'label' => null,
            'meta' => null,
            'position' => 0,
        ];
    }

    public function between(string $start, string $end): self
    {
        return $this->state(fn (): array => [
            'start_minute' => Time::fromString($start)->minutes,
            'end_minute' => Time::fromString($end)->minutes,
        ]);
    }
}
