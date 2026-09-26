<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\OpeningHours\ValueObjects\Time;

/**
 * Minutes since midnight (0..1440) ⇄ `Time`.
 *
 * @implements CastsAttributes<Time, Time|int|string>
 */
final class TimeCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Time
    {
        return $value === null ? null : new Time((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return match (true) {
            $value === null => null,
            $value instanceof Time => $value->minutes,
            is_string($value) && str_contains($value, ':') => Time::fromString($value)->minutes,
            default => (int) $value,
        };
    }
}
