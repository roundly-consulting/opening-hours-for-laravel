<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A calendar's definition changed (its revision moved). Dispatched after the
 * write commits; scalar payload, safe to queue.
 */
final readonly class OpeningHoursUpdated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $calendarId,
        public string $ownerType,
        public int|string $ownerId,
        public string $calendarKey,
        public int $revision,
    ) {}
}
