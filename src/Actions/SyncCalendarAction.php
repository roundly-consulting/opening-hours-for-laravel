<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\OpeningHours\Contracts\OpeningHoursOwner;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ScheduleData;
use RoundlyConsulting\OpeningHours\Events\OpeningHoursUpdated;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOpeningHoursException;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOwnerException;
use RoundlyConsulting\OpeningHours\Exceptions\StaleOpeningHoursException;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\ExceptionRange;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\Models\Schedule;
use RoundlyConsulting\OpeningHours\Models\ScheduleRange;
use RoundlyConsulting\OpeningHours\Support\CalendarModel;
use RoundlyConsulting\OpeningHours\Support\CalendarWriter;
use RoundlyConsulting\OpeningHours\Support\ExceptionRuleModel;
use RoundlyConsulting\OpeningHours\Support\RevisionGuard;
use RoundlyConsulting\OpeningHours\Support\ScheduleModel;
use RoundlyConsulting\OpeningHours\Support\WriteChecks;

/**
 * Writes a whole definition to an owner's calendar in one transaction:
 * validate → create-or-restore the header → lock → optimistic revision check →
 * upsert schedules and exceptions by id (ids must belong to this calendar) →
 * soft-delete what is gone → replace ranges → exactly one revision bump and
 * one `OpeningHoursUpdated` after commit.
 */
final readonly class SyncCalendarAction
{
    public function __construct(private Dispatcher $events) {}

    /**
     * @param  Model&OpeningHoursOwner  $owner
     *
     * @throws InvalidOwnerException
     * @throws InvalidOpeningHoursException
     * @throws StaleOpeningHoursException
     */
    public function execute(Model $owner, CalendarData $data, string $key, ?int $expectedRevision = null): Calendar
    {
        WriteChecks::sync($owner, $data, $key);

        $calendar = RevisionGuard::suppress(fn (): Calendar => CalendarWriter::transaction(
            fn (): Calendar => $this->write($owner, $data, $key, $expectedRevision),
        ));

        $this->events->dispatch(new OpeningHoursUpdated($calendar->id, $calendar->owner_type, $calendar->owner_id, $calendar->key, $calendar->revision));

        return $calendar;
    }

    private function write(Model $owner, CalendarData $data, string $key, ?int $expectedRevision): Calendar
    {
        $class = CalendarModel::class();
        $identity = ['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey(), 'key' => $key];

        $existing = $class::query()->withTrashed()->where($identity)->first();

        // A restore brings a calendar back to life: it counts like a new one.
        if ($existing === null || $existing->trashed()) {
            WriteChecks::calendarLimit($owner);
        }

        $created = $existing ?? $class::query()->withTrashed()->createOrFirst($identity, ['revision' => 0]);
        $calendar = CalendarWriter::lock($created->id);
        $isNew = $existing === null && $created->wasRecentlyCreated;

        WriteChecks::revision($expectedRevision, $isNew || $calendar->trashed(), $calendar->revision);

        if ($calendar->trashed()) {
            $calendar->restore();
        }

        $this->syncSchedules($calendar, $data->schedules);
        $this->syncExceptions($calendar, $data->exceptions);

        $calendar->forceFill(['timezone' => $data->timezone, 'label' => $data->label, 'meta' => $data->meta])->save();
        $calendar->increment('revision');

        return $calendar->refresh();
    }

    /**
     * @param  list<ScheduleData>  $schedules
     */
    private function syncSchedules(Calendar $calendar, array $schedules): void
    {
        $class = ScheduleModel::class();
        $existing = $class::query()->withTrashed()->where('calendar_id', $calendar->id)->get()->keyBy('id');
        WriteChecks::ids($existing->keys()->all(), $schedules, 'schedules');
        $kept = [];

        foreach ($schedules as $position => $schedule) {
            [$recurrence, $from, $until] = CalendarWriter::windowColumns($schedule->isBase() ? null : $schedule->window);
            $attributes = [
                'label' => $schedule->label,
                'priority' => $schedule->priority,
                'recurrence' => $recurrence,
                'effective_from' => $from,
                'effective_until' => $until,
                'position' => $position,
                'meta' => $schedule->meta,
            ];

            /** @var Schedule|null $model */
            $model = $schedule->id === null ? null : $existing->get($schedule->id);

            if ($model === null) {
                $model = $class::query()->create(['calendar_id' => $calendar->id, ...$attributes]);
            } else {
                if ($model->trashed()) {
                    $model->restore();
                }

                $model->forceFill($attributes)->save();
                ScheduleRange::query()->where('schedule_id', $model->id)->delete();
            }

            CalendarWriter::insertScheduleRanges($model->id, $schedule->week->ranges);
            $kept[] = $model->id;
        }

        $class::query()->where('calendar_id', $calendar->id)->whereNotIn('id', $kept)->get()->each->delete();
    }

    /**
     * @param  list<ExceptionData>  $exceptions
     */
    private function syncExceptions(Calendar $calendar, array $exceptions): void
    {
        $class = ExceptionRuleModel::class();
        $existing = $class::query()->withTrashed()->where('calendar_id', $calendar->id)->get()->keyBy('id');
        WriteChecks::ids($existing->keys()->all(), $exceptions, 'exceptions');
        $kept = [];

        foreach ($exceptions as $position => $exception) {
            [$recurrence, $from, $until] = CalendarWriter::windowColumns($exception->window);
            $attributes = [
                'recurrence' => $recurrence,
                'starts_on' => $from,
                'ends_on' => $until,
                'label' => $exception->label,
                'position' => $position,
                'meta' => $exception->meta,
            ];

            /** @var ExceptionRule|null $model */
            $model = $exception->id === null ? null : $existing->get($exception->id);

            if ($model === null) {
                $model = $class::query()->create(['calendar_id' => $calendar->id, ...$attributes]);
            } else {
                if ($model->trashed()) {
                    $model->restore();
                }

                $model->forceFill($attributes)->save();
                ExceptionRange::query()->where('exception_rule_id', $model->id)->delete();
            }

            CalendarWriter::insertExceptionRanges($model->id, $exception->ranges);
            $kept[] = $model->id;
        }

        $class::query()->where('calendar_id', $calendar->id)->whereNotIn('id', $kept)->get()->each->delete();
    }
}
