<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours;

use DateTimeZone;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\OpeningHours\Actions\AddExceptionAction;
use RoundlyConsulting\OpeningHours\Actions\BumpRevisionAction;
use RoundlyConsulting\OpeningHours\Actions\DeleteCalendarAction;
use RoundlyConsulting\OpeningHours\Actions\RemoveExceptionAction;
use RoundlyConsulting\OpeningHours\Actions\SyncCalendarAction;
use RoundlyConsulting\OpeningHours\Builders\CalendarBuilder;
use RoundlyConsulting\OpeningHours\Cache\DefinitionCache;
use RoundlyConsulting\OpeningHours\Contracts\OpeningHoursOwner;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\Engine\Compiler;
use RoundlyConsulting\OpeningHours\Engine\Definition;
use RoundlyConsulting\OpeningHours\Exceptions\CalendarNotFoundException;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\Support\CalendarModel;
use RoundlyConsulting\OpeningHours\Support\Settings;
use RoundlyConsulting\OpeningHours\Support\TimezoneResolver;
use RoundlyConsulting\OpeningHours\Validation\CalendarParser;
use RoundlyConsulting\OpeningHours\Validation\ParseOptions;
use RoundlyConsulting\OpeningHours\Validation\ViolationList;

/**
 * The facade root. Reads go header → per-request memo `[calendarId:revision]` →
 * definition cache → database; writes go through the actions and drop the
 * owner's stale loaded relation. Bound `scoped`, so the memo never outlives a
 * request or a queued job (Octane-safe). Not final: `OpeningHoursFake` extends it.
 */
class OpeningHoursManager
{
    /** @var array<string, Definition> */
    private array $memo = [];

    /**
     * Actions are resolved per call, so host rebinds and a later
     * `Event::fake()` reach them even though this manager lives a whole request.
     */
    public function __construct(
        private readonly DefinitionCache $cache,
        private readonly Container $container,
    ) {}

    /**
     * @param  Model&OpeningHoursOwner  $owner
     */
    public function for(Model $owner, ?string $calendar = null): OpeningHours
    {
        $header = $this->calendar($owner, $calendar);

        if ($header === null) {
            return OpeningHours::empty(TimezoneResolver::resolve($owner->openingHoursTimezone()))
                ->withDynamicExceptions(...$owner->openingHoursDynamicExceptions());
        }

        return $this->build($header, $owner);
    }

    /**
     * The live calendar header, or null. A loaded `openingHoursCalendars`
     * relation is authoritative (no query).
     *
     * @param  Model&OpeningHoursOwner  $owner
     */
    public function calendar(Model $owner, ?string $calendar = null): ?Calendar
    {
        if (! $owner->exists) {
            return null;
        }

        $key = $calendar ?? Settings::defaultCalendar();

        if ($owner->relationLoaded('openingHoursCalendars')) {
            /** @var iterable<Calendar> $loaded */
            $loaded = $owner->getRelation('openingHoursCalendars');

            foreach ($loaded as $header) {
                if ($header->key === $key && ! $header->trashed()) {
                    return $header;
                }
            }

            return null;
        }

        return $owner->openingHoursCalendars()->where('key', $key)->first();
    }

    /**
     * @param  Model&OpeningHoursOwner  $owner
     */
    public function has(Model $owner, ?string $calendar = null): bool
    {
        return $this->calendar($owner, $calendar) !== null;
    }

    /**
     * A builder seeded with the current definition; `save()` is optimistic
     * against the revision loaded here.
     *
     * @param  Model&OpeningHoursOwner  $owner
     */
    public function edit(Model $owner, ?string $calendar = null): CalendarBuilder
    {
        $header = $this->calendar($owner, $calendar);
        $current = $header === null ? null : $this->definitionData($header);

        return CalendarBuilder::attached(
            $current,
            $header === null ? 0 : $header->revision,
            fn (CalendarData $data, ?int $expected): OpeningHours => $this->sync($owner, $data, $calendar, $expected),
        );
    }

    /**
     * Replace the calendar's whole definition (array input is validated first).
     *
     * @param  Model&OpeningHoursOwner  $owner
     * @param  CalendarData|array<mixed>  $data
     */
    public function sync(Model $owner, CalendarData|array $data, ?string $calendar = null, ?int $expectedRevision = null): OpeningHours
    {
        $data = is_array($data) ? CalendarData::fromArray($data) : $data;
        $header = $this->container->make(SyncCalendarAction::class)->execute($owner, $data, $calendar ?? Settings::defaultCalendar(), $expectedRevision);
        $owner->unsetRelation('openingHoursCalendars');

        return $this->build($header, $owner);
    }

    /**
     * An in-memory calendar; no database involved.
     *
     * @param  CalendarData|array<mixed>  $data
     */
    public function make(CalendarData|array $data, DateTimeZone|string|null $timezone = null): OpeningHours
    {
        return OpeningHours::make($data, $timezone);
    }

    /**
     * Soft-delete the calendar (the next sync restores it), or with `force`
     * remove it for good — a soft-deleted one included. `false` when absent.
     */
    public function delete(Model $owner, ?string $calendar = null, bool $force = false): bool
    {
        $header = $this->deletableCalendar($owner, $calendar, $force);

        if ($header === null) {
            return false;
        }

        $deleted = $this->container->make(DeleteCalendarAction::class)->execute($header, $force);
        $owner->unsetRelation('openingHoursCalendars');

        return $deleted;
    }

    /**
     * The exceptions of one calendar: add, `closed()`, `open()`, `remove()`
     * and `all()` — race-safe single-exception writes (see `CalendarExceptions`).
     *
     * @param  Model&OpeningHoursOwner  $owner
     */
    public function exceptions(Model $owner, ?string $calendar = null): CalendarExceptions
    {
        return new CalendarExceptions($this, $owner, $calendar);
    }

    /**
     * Roll the revision (e.g. after changing what the owner's timezone hook
     * returns, or after raw SQL edits) so caches and materialized intervals follow.
     *
     * @param  Model&OpeningHoursOwner  $owner
     */
    public function refresh(Model $owner, ?string $calendar = null): void
    {
        $header = $this->calendar($owner, $calendar);

        if ($header !== null) {
            $this->container->make(BumpRevisionAction::class)->execute($header->id);
        }

        $owner->unsetRelation('openingHoursCalendars');
    }

    /**
     * @param  array<mixed>  $payload
     */
    public function validate(array $payload, ?ParseOptions $options = null): ViolationList
    {
        return CalendarParser::parse($payload, $options ?? new ParseOptions)->violations;
    }

    public function flushMemo(): void
    {
        $this->memo = [];
    }

    /**
     * @internal drops one calendar's memo entries; wired to the update/delete events
     */
    public function forgetCalendar(int $calendarId): void
    {
        foreach (array_keys($this->memo) as $key) {
            if (str_starts_with($key, $calendarId.':')) {
                unset($this->memo[$key]);
            }
        }
    }

    /**
     * The stored definition of a calendar header, through memo and cache.
     */
    public function definitionData(Calendar $calendar): CalendarData
    {
        return $this->definition($calendar)->data;
    }

    /**
     * @internal the write path of `exceptions($owner)->add()`
     *
     * @param  Model&OpeningHoursOwner  $owner
     */
    public function addException(Model $owner, ExceptionData $data, ?string $calendar = null): ExceptionRule
    {
        $rule = $this->container->make(AddExceptionAction::class)->execute($this->liveCalendar($owner, $calendar), $data);
        $owner->unsetRelation('openingHoursCalendars');

        return $rule;
    }

    /**
     * @internal the write path of `exceptions($owner)->remove()`
     *
     * @param  Model&OpeningHoursOwner  $owner
     */
    public function removeException(Model $owner, int $ruleId, ?string $calendar = null): bool
    {
        $removed = $this->container->make(RemoveExceptionAction::class)->execute($this->liveCalendar($owner, $calendar), $ruleId);
        $owner->unsetRelation('openingHoursCalendars');

        return $removed;
    }

    /**
     * @internal the models' revision hook for direct row edits; hosts call `refresh()`
     */
    public function bumpRevision(int $calendarId): int
    {
        return $this->container->make(BumpRevisionAction::class)->execute($calendarId);
    }

    /**
     * The header `delete()` would remove: a live one, or with `force` a
     * soft-deleted one too.
     */
    protected function deletableCalendar(Model $owner, ?string $calendar, bool $force): ?Calendar
    {
        if ($owner->getKey() === null) {
            return null;
        }

        $class = CalendarModel::class();
        $query = $class::query()->forOwner($owner)->forKey($calendar ?? Settings::defaultCalendar());

        return ($force ? $query->withTrashed() : $query)->first();
    }

    /**
     * A fresh read of the live header (a loaded relation may be stale for a write).
     *
     * @param  Model&OpeningHoursOwner  $owner
     *
     * @throws CalendarNotFoundException
     */
    protected function liveCalendar(Model $owner, ?string $calendar): Calendar
    {
        $key = $calendar ?? Settings::defaultCalendar();
        $header = $owner->exists ? $owner->openingHoursCalendars()->where('key', $key)->first() : null;

        return $header ?? throw CalendarNotFoundException::forKey($key);
    }

    /**
     * @param  Model&OpeningHoursOwner  $owner
     */
    private function build(Calendar $header, Model $owner): OpeningHours
    {
        return OpeningHours::fromDefinition(
            $this->definition($header),
            TimezoneResolver::resolve($header->timezone, $owner->openingHoursTimezone()),
            $owner->openingHoursDynamicExceptions(),
        );
    }

    private function definition(Calendar $calendar): Definition
    {
        $key = $calendar->id.':'.$calendar->revision;

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $data = $this->cache->get($calendar->id, $calendar->revision);

        if ($data === null) {
            $data = $calendar->toData();
            $this->cache->put($calendar->id, $calendar->revision, $data);
        }

        return $this->memo[$key] = Compiler::compile($data->withRevision($calendar->revision));
    }
}
