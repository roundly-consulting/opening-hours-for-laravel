<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Tests\Fixtures;

use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host subclass proving the `Calendar` seam.
 */
class CustomCalendar extends Calendar
{
    use CountsCreations;
}
