<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Concerns;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\OpeningHours\Builders\CalendarBuilder;
use RoundlyConsulting\OpeningHours\Contracts\DynamicExceptionProvider;
use RoundlyConsulting\OpeningHours\Contracts\OpeningHoursOwner;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOwnerException;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\OpeningHours;
use RoundlyConsulting\OpeningHours\OpeningHoursManager;
use RoundlyConsulting\OpeningHours\Support\CalendarModel;
use RoundlyConsulting\OpeningHours\Support\IntervalScopes;
use RoundlyConsulting\OpeningHours\Support\Settings;

/**
 * Opening hours for any Eloquent model. Implement `OpeningHoursOwner` next to it.
 *
 * `openingHours()` is a METHOD returning the query object, not a relation —
 * the relation is `openingHoursCalendars()`.
 *
 * @mixin Model
 */
trait HasOpeningHours
{
    /**
     * A permanently deleted owner takes its calendars with it (when
     * `delete_with_owner`); soft-deleting the owner keeps them.
     */
    public static function bootHasOpeningHours(): void
    {
        static::deleted(static function (Model $owner): void {
            if (! Settings::deleteWithOwner()) {
                return;
            }

            $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($owner), true);

            if ($softDeletes && method_exists($owner, 'isForceDeleting') && ! $owner->isForceDeleting()) {
                return;
            }

            $class = CalendarModel::class();
            $keys = $class::query()->withTrashed()->forOwner($owner)->pluck('key');
            $manager = app(OpeningHoursManager::class);

            foreach ($keys as $key) {
                $manager->delete($owner, $key, force: true);
            }
        });
    }

    /**
     * @return MorphMany<Calendar, $this>
     */
    public function openingHoursCalendars(): MorphMany
    {
        return $this->morphMany(CalendarModel::class(), 'owner');
    }

    public function openingHoursCalendar(?string $calendar = null): ?Calendar
    {
        return app(OpeningHoursManager::class)->calendar($this->openingHoursOwner(), $calendar);
    }

    public function openingHours(?string $calendar = null): OpeningHours
    {
        return app(OpeningHoursManager::class)->for($this->openingHoursOwner(), $calendar);
    }

    public function hasOpeningHours(?string $calendar = null): bool
    {
        return app(OpeningHoursManager::class)->has($this->openingHoursOwner(), $calendar);
    }

    /**
     * @param  CalendarData|array<mixed>  $data
     */
    public function setOpeningHours(CalendarData|array $data, ?string $calendar = null, ?int $expectedRevision = null): OpeningHours
    {
        return app(OpeningHoursManager::class)->sync($this->openingHoursOwner(), $data, $calendar, $expectedRevision);
    }

    public function editOpeningHours(?string $calendar = null): CalendarBuilder
    {
        return app(OpeningHoursManager::class)->edit($this->openingHoursOwner(), $calendar);
    }

    /**
     * Override to evaluate calendars without their own timezone in the owner's.
     */
    public function openingHoursTimezone(): ?string
    {
        return null;
    }

    /**
     * Override to add computed exceptions (movable holidays…).
     *
     * @return list<DynamicExceptionProvider>
     */
    public function openingHoursDynamicExceptions(): array
    {
        return [];
    }

    /**
     * Eager-load every live calendar header (enough with the cache on); pass
     * `definitions: true` to also load schedules, exceptions and ranges.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithOpeningHours(Builder $query, bool $definitions = false): void
    {
        $query->with($definitions
            ? ['openingHoursCalendars.schedules.ranges', 'openingHoursCalendars.exceptionRules.ranges']
            : ['openingHoursCalendars']);
    }

    /**
     * Owners open at an instant, from materialized intervals (requires
     * `materialize.enabled` and a daily `opening-hours:materialize`).
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereOpenAt(Builder $query, DateTimeInterface $at, ?string $calendar = null): void
    {
        IntervalScopes::openAt($query, $at, $calendar);
    }

    /**
     * Owners open for the whole of `[$start, $end)`, from materialized intervals.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereOpenThroughout(Builder $query, DateTimeInterface $start, DateTimeInterface $end, ?string $calendar = null): void
    {
        IntervalScopes::openThroughout($query, $start, $end, $calendar);
    }

    /**
     * @return Model&OpeningHoursOwner
     */
    private function openingHoursOwner(): Model
    {
        if (! $this instanceof OpeningHoursOwner) {
            throw InvalidOwnerException::notAnOwner(static::class);
        }

        return $this;
    }
}
