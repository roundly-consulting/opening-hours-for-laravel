<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Tests\Fixtures;

use RoundlyConsulting\OpeningHours\Models\Schedule;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host subclass proving the `Schedule` seam.
 */
class CustomSchedule extends Schedule
{
    use CountsCreations;
}
