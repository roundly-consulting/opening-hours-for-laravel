<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\OpeningHours\Enums\Weekday;

/**
 * ISO weekday number (1 = Monday) ⇄ `Weekday`.
 *
 * @implements CastsAttributes<Weekday, Weekday|int>
 */
final class WeekdayCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Weekday
    {
        return $value === null ? null : Weekday::fromIso((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof Weekday ? $value->iso() : (int) $value;
    }
}
