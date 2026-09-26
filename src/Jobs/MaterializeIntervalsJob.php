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
        $calendar = $class::query()->withTrashed()->find($this->calendarId);

        if ($calendar === null) {
            return;
        }

        [$from, $to] = Materialize::window(Materialize::hoursFor($calendar));
        $action->execute($calendar, $from, $to);
    }
}
