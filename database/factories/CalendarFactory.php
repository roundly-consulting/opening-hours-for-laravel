<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Support\CalendarModel;

/**
 * @extends Factory<Calendar>
 */
final class CalendarFactory extends Factory
{
    protected $model = Calendar::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_type' => 'owner',
            'owner_id' => $this->faker->unique()->numberBetween(1, 1_000_000),
            'key' => 'default',
            'label' => null,
            'timezone' => null,
            'revision' => 0,
            'meta' => null,
        ];
    }

    public function modelName(): string
    {
        return CalendarModel::class();
    }

    public function forOwner(Model $owner): self
    {
        return $this->state(fn (): array => [
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ]);
    }

    public function timezone(?string $timezone): self
    {
        return $this->state(fn (): array => ['timezone' => $timezone]);
    }

    public function key(string $key): self
    {
        return $this->state(fn (): array => ['key' => $key]);
    }
}
