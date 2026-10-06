<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Support\CalendarModel;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

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
            'owner_id' => self::ownerKey($this->faker->unique()->numberBetween(1, 1_000_000)),
            'key' => 'default',
            'label' => null,
            'timezone' => null,
            'revision' => 0,
            'meta' => null,
        ];
    }

    /**
     * A fake owner key of the configured `key_type`: on a uuid or ulid host `owner_id` is a
     * uuid/ulid column, which a strict engine (PostgreSQL) refuses an integer for.
     */
    public static function ownerKey(int $bigint): int|string
    {
        return match (KeyType::fromConfig('opening-hours.key_type')) {
            KeyType::BigInt => $bigint,
            KeyType::Uuid => (string) Str::uuid(),
            KeyType::Ulid => (string) Str::ulid(),
        };
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
