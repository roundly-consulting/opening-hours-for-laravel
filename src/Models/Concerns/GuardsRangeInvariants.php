<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidTimeException;

/**
 * Defence in depth for direct Eloquent writes of range rows: the validator is
 * bypassed there, so the row itself refuses impossible values.
 *
 * @phpstan-require-extends Model
 */
trait GuardsRangeInvariants
{
    public static function bootGuardsRangeInvariants(): void
    {
        static::registerModelEvent('saving', static function (Model $model): void {
            $attributes = $model->getAttributes();
            $start = (int) ($attributes['start_minute'] ?? -1);
            $end = (int) ($attributes['end_minute'] ?? -1);

            if ($start < 0 || $start > 1439) {
                throw InvalidTimeException::outOfRange($start);
            }

            if ($end < 1 || $end > 1440) {
                throw InvalidTimeException::outOfRange($end);
            }

            if ($start === $end) {
                throw InvalidTimeException::emptyRange(sprintf('%d-%d', $start, $end));
            }

            if (method_exists($model, 'guardsWeekday') && $model->guardsWeekday()) {
                $weekday = (int) ($attributes['weekday'] ?? 0);

                if ($weekday < 1 || $weekday > 7) {
                    throw InvalidTimeException::invalidWeekday($weekday);
                }
            }
        });
    }
}
