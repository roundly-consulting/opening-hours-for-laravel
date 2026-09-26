<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;

/**
 * `date` column ⇄ `LocalDate`. Never through Carbon, so no timezone can shift it.
 *
 * @implements CastsAttributes<LocalDate, LocalDate|string>
 */
final class LocalDateCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?LocalDate
    {
        if ($value === null || $value === '') {
            return null;
        }

        return LocalDate::fromString(substr((string) $value, 0, 10));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof LocalDate ? $value->toDateString() : LocalDate::fromString((string) $value)->toDateString();
    }
}
