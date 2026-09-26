<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\OpeningHours\Enums\Recurrence;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\Support\ExceptionRuleModel;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\OpeningHours\ValueObjects\MonthDay;

/**
 * @extends Factory<ExceptionRule>
 */
final class ExceptionRuleFactory extends Factory
{
    protected $model = ExceptionRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'calendar_id' => CalendarFactory::new(),
            'recurrence' => Recurrence::None,
            'starts_on' => '2026-12-24',
            'ends_on' => '2026-12-24',
            'label' => null,
            'position' => 0,
            'meta' => null,
        ];
    }

    public function modelName(): string
    {
        return ExceptionRuleModel::class();
    }

    /**
     * A closed day: simply no ranges (the default).
     */
    public function closed(): self
    {
        return $this;
    }

    public function on(string $from, ?string $until = null): self
    {
        return $this->state(fn (): array => [
            'recurrence' => Recurrence::None,
            'starts_on' => LocalDate::fromString($from),
            'ends_on' => LocalDate::fromString($until ?? $from),
        ]);
    }

    public function yearly(string $from, ?string $until = null): self
    {
        return $this->state(fn (): array => [
            'recurrence' => Recurrence::Yearly,
            'starts_on' => MonthDay::fromString($from)->toDateIn(2000),
            'ends_on' => MonthDay::fromString($until ?? $from)->toDateIn(2000, asEnd: true),
        ]);
    }
}
