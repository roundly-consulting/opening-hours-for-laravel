<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\OpeningHours\Contracts\DateWindow;
use RoundlyConsulting\OpeningHours\Enums\Recurrence;
use RoundlyConsulting\OpeningHours\Models\Schedule;
use RoundlyConsulting\OpeningHours\Support\ScheduleModel;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\YearlyWindow;

/**
 * @extends Factory<Schedule>
 */
final class ScheduleFactory extends Factory
{
    protected $model = Schedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'calendar_id' => CalendarFactory::new(),
            'label' => null,
            'priority' => 0,
            'recurrence' => Recurrence::None,
            'effective_from' => null,
            'effective_until' => null,
            'position' => 0,
            'meta' => null,
        ];
    }

    public function modelName(): string
    {
        return ScheduleModel::class();
    }

    public function base(): self
    {
        return $this->state(fn (): array => ['recurrence' => Recurrence::None, 'effective_from' => null, 'effective_until' => null]);
    }

    public function seasonal(DateWindow $window, int $priority = 0): self
    {
        return $this->state(fn (): array => match (true) {
            $window instanceof YearlyWindow => [
                'recurrence' => Recurrence::Yearly,
                'effective_from' => $window->from->toDateIn(2000),
                'effective_until' => $window->until->toDateIn(2000, asEnd: true),
                'priority' => $priority,
            ],
            $window instanceof AbsoluteWindow => [
                'recurrence' => Recurrence::None,
                'effective_from' => $window->from,
                'effective_until' => $window->until,
                'priority' => $priority,
            ],
            default => ['priority' => $priority],
        });
    }
}
