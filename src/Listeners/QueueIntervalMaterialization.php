<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use RoundlyConsulting\OpeningHours\Events\OpeningHoursUpdated;
use RoundlyConsulting\OpeningHours\Jobs\MaterializeIntervalsJob;
use RoundlyConsulting\OpeningHours\Support\Materialize;

/**
 * Re-materializes a calendar after every change, when `materialize.enabled`.
 */
final readonly class QueueIntervalMaterialization
{
    public function __construct(private Dispatcher $bus) {}

    public function handle(OpeningHoursUpdated $event): void
    {
        if (! Materialize::enabled()) {
            return;
        }

        $this->bus->dispatch(new MaterializeIntervalsJob($event->calendarId));
    }
}
