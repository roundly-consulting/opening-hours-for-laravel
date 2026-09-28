<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\OpeningHours\Events\OpeningHoursUpdated;
use RoundlyConsulting\OpeningHours\Support\CalendarModel;

/**
 * Atomically increments a calendar's revision — which retires every cached
 * definition of it — and announces the change after commit. Hosts reach it
 * through `OpeningHours::refresh()`.
 */
final readonly class BumpRevisionAction
{
    public function __construct(private Dispatcher $events) {}

    public function execute(int $calendarId): int
    {
        $class = CalendarModel::class();
        $class::query()->withTrashed()->whereKey($calendarId)->increment('revision');
        $calendar = $class::query()->withTrashed()->whereKey($calendarId)->first();

        if ($calendar === null) {
            return 0;
        }

        $this->events->dispatch(new OpeningHoursUpdated(
            $calendar->id,
            $calendar->owner_type,
            $calendar->owner_id,
            $calendar->key,
            $calendar->revision,
        ));

        return $calendar->revision;
    }
}
