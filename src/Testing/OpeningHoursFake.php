<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Testing;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\OpeningHours\Contracts\OpeningHoursOwner;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\OpeningHours;
use RoundlyConsulting\OpeningHours\OpeningHoursManager;
use RoundlyConsulting\OpeningHours\Support\CalendarModel;
use RoundlyConsulting\OpeningHours\Support\CalendarWriter;
use RoundlyConsulting\OpeningHours\Support\ExceptionRuleModel;
use RoundlyConsulting\OpeningHours\Support\ScheduleModel;
use RoundlyConsulting\OpeningHours\Support\Settings;
use RoundlyConsulting\OpeningHours\Support\TimezoneResolver;
use RoundlyConsulting\OpeningHours\Support\WriteChecks;

/**
 * `OpeningHours::fake()`: every write — `sync()`, `edit()->save()`,
 * `delete()`, `refresh()`, `exceptions()->add/closed/open/remove()` and the
 * owner trait's `setOpeningHours()` / `editOpeningHours()` / delete cascade —
 * runs the same checks as the real manager against the database as it is
 * (owner, calendar key, definition, limits, ids, revision, missing calendar)
 * and is recorded but never persisted, so no events fire and no intervals are
 * materialized. A write the real manager would skip (`false`, nothing to
 * delete, refresh or remove) returns the same and is not recorded. Reads still
 * go to the database.
 */
final class OpeningHoursFake extends OpeningHoursManager
{
    /** @var list<RecordedWrite> */
    private array $writes = [];

    /**
     * @param  Model&OpeningHoursOwner  $owner
     * @param  CalendarData|array<mixed>  $data
     */
    public function sync(Model $owner, CalendarData|array $data, ?string $calendar = null, ?int $expectedRevision = null): OpeningHours
    {
        $data = is_array($data) ? CalendarData::fromArray($data) : $data;
        $key = $calendar ?? Settings::defaultCalendar();
        WriteChecks::sync($owner, $data, $key);

        $class = CalendarModel::class();
        $existing = $class::query()->withTrashed()->forOwner($owner)->forKey($key)->first();

        if ($existing === null) {
            WriteChecks::calendarLimit($owner);
        }

        WriteChecks::revision($expectedRevision, $existing === null || $existing->trashed(), $existing->revision ?? 0);
        WriteChecks::ids($existing === null ? [] : ScheduleModel::class()::query()->withTrashed()->where('calendar_id', $existing->id)->pluck('id')->all(), $data->schedules, 'schedules');
        WriteChecks::ids($existing === null ? [] : ExceptionRuleModel::class()::query()->withTrashed()->where('calendar_id', $existing->id)->pluck('id')->all(), $data->exceptions, 'exceptions');

        $this->record('sync', $owner, $calendar, $data);

        return OpeningHours::make($data, TimezoneResolver::resolve($data->timezone, $owner->openingHoursTimezone()))
            ->withDynamicExceptions(...$owner->openingHoursDynamicExceptions());
    }

    public function delete(Model $owner, ?string $calendar = null, bool $force = false): bool
    {
        if ($this->deletableCalendar($owner, $calendar, $force) === null) {
            return false;
        }

        $this->record('delete', $owner, $calendar, $force);

        return true;
    }

    /**
     * @param  Model&OpeningHoursOwner  $owner
     */
    public function refresh(Model $owner, ?string $calendar = null): void
    {
        if ($this->calendar($owner, $calendar) !== null) {
            $this->record('refresh', $owner, $calendar);
        }
    }

    /**
     * An unsaved rule shaped like the one the real write would create.
     *
     * @internal the write path of `exceptions($owner)->add()`
     *
     * @param  Model&OpeningHoursOwner  $owner
     */
    public function addException(Model $owner, ExceptionData $data, ?string $calendar = null): ExceptionRule
    {
        $header = $this->liveCalendar($owner, $calendar);
        $checked = WriteChecks::exception($header->toData(), $data);

        $this->record('add', $owner, $calendar, $data);
        [$recurrence, $from, $until] = CalendarWriter::windowColumns($checked->window);
        $class = ExceptionRuleModel::class();

        return (new $class)->forceFill([
            'calendar_id' => $header->id,
            'recurrence' => $recurrence,
            'starts_on' => $from,
            'ends_on' => $until,
            'label' => $checked->label,
            'meta' => $checked->meta,
        ]);
    }

    /**
     * @internal the write path of `exceptions($owner)->remove()`
     *
     * @param  Model&OpeningHoursOwner  $owner
     */
    public function removeException(Model $owner, int $ruleId, ?string $calendar = null): bool
    {
        $header = $this->liveCalendar($owner, $calendar);

        if (! ExceptionRuleModel::class()::query()->where('calendar_id', $header->id)->whereKey($ruleId)->exists()) {
            return false;
        }

        $this->record('remove', $owner, $calendar, $ruleId);

        return true;
    }

    /**
     * @return list<RecordedWrite>
     */
    public function writes(): array
    {
        return $this->writes;
    }

    /**
     * @param  (Closure(CalendarData): bool)|null  $callback
     */
    public function assertSynced(Model $owner, ?string $calendar = null, ?Closure $callback = null): void
    {
        Assert::assertNotEmpty(
            $this->matching('sync', $owner, $calendar, $callback),
            "Expected opening hours of the [{$this->key($calendar)}] calendar to be synced, but they were not.",
        );
    }

    public function assertNothingSynced(): void
    {
        $this->assertNone('sync', 'Expected no opening hours to be synced');
    }

    public function assertDeleted(Model $owner, ?string $calendar = null, ?bool $force = null): void
    {
        $callback = $force === null ? null : static fn (bool $forced): bool => $forced === $force;

        Assert::assertNotEmpty(
            $this->matching('delete', $owner, $calendar, $callback),
            "Expected the [{$this->key($calendar)}] calendar to be deleted, but it was not.",
        );
    }

    public function assertNothingDeleted(): void
    {
        $this->assertNone('delete', 'Expected no calendar to be deleted');
    }

    public function assertRefreshed(Model $owner, ?string $calendar = null): void
    {
        Assert::assertNotEmpty(
            $this->matching('refresh', $owner, $calendar),
            "Expected the [{$this->key($calendar)}] calendar to be refreshed, but it was not.",
        );
    }

    public function assertNothingRefreshed(): void
    {
        $this->assertNone('refresh', 'Expected no calendar to be refreshed');
    }

    /**
     * @param  (Closure(ExceptionData): bool)|null  $callback
     */
    public function assertExceptionAdded(Model $owner, ?string $calendar = null, ?Closure $callback = null): void
    {
        Assert::assertNotEmpty(
            $this->matching('add', $owner, $calendar, $callback),
            "Expected an exception to be added to the [{$this->key($calendar)}] calendar, but none was.",
        );
    }

    public function assertNoExceptionsAdded(): void
    {
        $this->assertNone('add', 'Expected no exception to be added');
    }

    public function assertExceptionRemoved(Model $owner, ?int $ruleId = null, ?string $calendar = null): void
    {
        $callback = $ruleId === null ? null : static fn (int $removed): bool => $removed === $ruleId;

        Assert::assertNotEmpty(
            $this->matching('remove', $owner, $calendar, $callback),
            "Expected an exception to be removed from the [{$this->key($calendar)}] calendar, but none was.",
        );
    }

    public function assertNoExceptionsRemoved(): void
    {
        $this->assertNone('remove', 'Expected no exception to be removed');
    }

    public function assertNothingWritten(): void
    {
        Assert::assertSame([], $this->writes, sprintf('Expected no opening-hours writes, but %d were recorded.', count($this->writes)));
    }

    private function record(string $operation, Model $owner, ?string $calendar, CalendarData|ExceptionData|int|bool|null $payload = null): void
    {
        $this->writes[] = new RecordedWrite($operation, $owner, $this->key($calendar), $payload);
    }

    /**
     * @param  (Closure(mixed): bool)|null  $callback
     * @return list<RecordedWrite>
     */
    private function matching(string $operation, Model $owner, ?string $calendar, ?Closure $callback = null): array
    {
        $key = $this->key($calendar);

        return array_values(array_filter(
            $this->writes,
            static fn (RecordedWrite $write): bool => $write->isFor($operation, $owner, $key)
                && ($callback === null || $callback($write->payload) === true),
        ));
    }

    private function assertNone(string $operation, string $message): void
    {
        $count = count(array_filter($this->writes, static fn (RecordedWrite $write): bool => $write->operation === $operation));

        Assert::assertSame(0, $count, "{$message}, but {$count} were recorded.");
    }

    private function key(?string $calendar): string
    {
        return $calendar ?? Settings::defaultCalendar();
    }
}
