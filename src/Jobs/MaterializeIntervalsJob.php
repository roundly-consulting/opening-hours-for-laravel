<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RoundlyConsulting\OpeningHours\Actions\MaterializeIntervalsAction;
use RoundlyConsulting\OpeningHours\Support\CalendarModel;
use RoundlyConsulting\OpeningHours\Support\Materialize;

/**
 * Rebuilds one calendar's materialized intervals over the configured horizon.
 * Unique per calendar, and carries only the id (queue-safe).
 */
final class MaterializeIntervalsJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    private const int MAX_PASSES = 5;

    public function __construct(public readonly int $calendarId)
    {
        $this->onConnection(Materialize::connection());
        $this->onQueue(Materialize::queue());
    }

    public function uniqueId(): string
    {
        return (string) $this->calendarId;
    }

    public function handle(MaterializeIntervalsAction $action): void
    {
        $class = CalendarModel::class();

        // A change committed while this job runs cannot queue another one (the unique
        // lock is held until the job ends), so repeat until the state it started from
        // is still current — otherwise the intervals would stay stale until the next roll.
        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $calendar = $class::query()->withTrashed()->find($this->calendarId);

            if ($calendar === null) {
                return;
            }

            [$from, $to] = Materialize::window(Materialize::hoursFor($calendar));
            $action->execute($calendar, $from, $to);

            $current = $class::query()->withTrashed()->find($this->calendarId);

            if ($current === null || ($current->revision === $calendar->revision && $current->trashed() === $calendar->trashed())) {
                return;
            }
        }
    }
}
