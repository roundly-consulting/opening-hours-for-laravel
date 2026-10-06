<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\OpeningHours\Models\Interval;

/**
 * @extends Factory<Interval>
 */
final class IntervalFactory extends Factory
{
    protected $model = Interval::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'calendar_id' => CalendarFactory::new(),
            'owner_type' => 'owner',
            'owner_id' => CalendarFactory::ownerKey(1),
            'calendar_key' => 'default',
            'opens_at' => '2026-09-28 07:00:00',
            'closes_at' => '2026-09-28 15:00:00',
        ];
    }
}
