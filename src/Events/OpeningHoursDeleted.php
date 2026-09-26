<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A calendar was soft-deleted (`forced: false`) or removed for good.
 */
final readonly class OpeningHoursDeleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $calendarId,
        public string $ownerType,
        public int|string $ownerId,
        public string $calendarKey,
        public bool $forced,
    ) {}
}
