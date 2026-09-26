<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\OpeningHours\Events\OpeningHoursDeleted;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\Interval;
use RoundlyConsulting\OpeningHours\Support\CalendarWriter;

/**
 * Soft-deletes a calendar (restorable by the next sync) or removes it for good
 * (the database cascades its schedules, exceptions and ranges). A soft delete
 * also drops the calendar's materialized intervals so SQL scopes never match it.
 */
final readonly class DeleteCalendarAction
{
    public function __construct(private Dispatcher $events) {}

    public function execute(Calendar $calendar, bool $force = false): bool
    {
        $deleted = CalendarWriter::transaction(function () use ($calendar, $force): bool {
            Interval::query()->where('calendar_id', $calendar->id)->delete();

            return (bool) ($force ? $calendar->forceDelete() : $calendar->delete());
        });

        if ($deleted) {
            $this->events->dispatch(new OpeningHoursDeleted($calendar->id, $calendar->owner_type, $calendar->owner_id, $calendar->key, $force));
        }

        return $deleted;
    }
}
