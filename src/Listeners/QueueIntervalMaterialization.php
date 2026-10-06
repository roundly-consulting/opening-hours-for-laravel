<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use RoundlyConsulting\OpeningHours\Events\OpeningHoursUpdated;
use RoundlyConsulting\OpeningHours\Support\Materialize;

/**
 * Re-materializes a calendar after every change, when `materialize.enabled` —
 * one pending job per calendar.
 */
final readonly class QueueIntervalMaterialization
{
    public function __construct(
        private Dispatcher $bus,
        private Repository $cache,
    ) {}

    public function handle(OpeningHoursUpdated $event): void
    {
        if (! Materialize::enabled()) {
            return;
        }

        Materialize::dispatchJob($event->calendarId, $this->bus, $this->cache);
    }
}
