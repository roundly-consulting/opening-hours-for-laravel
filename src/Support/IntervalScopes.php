<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Support;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RoundlyConsulting\OpeningHours\Exceptions\OutsideMaterializedHorizonException;
use RoundlyConsulting\OpeningHours\Models\Interval;

/**
 * The SQL side of `whereOpenAt` / `whereOpenThroughout`: an owner-correlated
 * EXISTS over materialized intervals. Bindings are UTC strings — a Carbon
 * binding would be formatted in its own timezone and compare local wall time
 * against UTC columns.
 *
 * @internal
 */
final class IntervalScopes
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function openAt(Builder $query, DateTimeInterface $at, ?string $calendar): void
    {
        $instant = self::utc($at);

        self::exists($query, $calendar, static function (QueryBuilder $intervals) use ($instant): void {
            $intervals->where('opens_at', '<=', $instant)->where('closes_at', '>', $instant);
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function openThroughout(Builder $query, DateTimeInterface $start, DateTimeInterface $end, ?string $calendar): void
    {
        $from = self::utc($start);
        $until = self::utc($end);

        self::exists($query, $calendar, static function (QueryBuilder $intervals) use ($from, $until): void {
            $intervals->where('opens_at', '<=', $from)->where('closes_at', '>=', $until);
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(QueryBuilder): void  $constraint
     */
    private static function exists(Builder $query, ?string $calendar, callable $constraint): void
    {
        $owner = $query->getModel();
        $table = (new Interval)->getTable();
        $key = $calendar ?? Settings::defaultCalendar();

        $query->whereExists(static function (QueryBuilder $intervals) use ($owner, $table, $key, $constraint): void {
            $intervals->select($table.'.id')
                ->from($table)
                ->where($table.'.owner_type', $owner->getMorphClass())
                ->whereColumn($table.'.owner_id', $owner->getQualifiedKeyName())
                ->where($table.'.calendar_key', $key);

            $constraint($intervals);
        });
    }

    /**
     * The horizon is `now − days_behind` … `now + days_ahead − 1 day`: a run stores
     * whole local days up to its own `today + days_ahead`, so with daily runs at
     * least that much is materialized until the next one.
     */
    private static function utc(DateTimeInterface $instant): string
    {
        $now = Clock::now();
        $from = $now->subDays(Materialize::daysBehind());
        $until = $now->addDays(Materialize::daysAhead() - 1);

        if ($instant->getTimestamp() < $from->getTimestamp() || $instant->getTimestamp() > $until->getTimestamp()) {
            throw OutsideMaterializedHorizonException::make($instant->format(DATE_ATOM), $from->format(DATE_ATOM), $until->format(DATE_ATOM));
        }

        return gmdate('Y-m-d H:i:s', $instant->getTimestamp());
    }
}
