<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Tests\Fixtures;

use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host subclass proving the `ExceptionRule` seam.
 */
class CustomExceptionRule extends ExceptionRule
{
    use CountsCreations;
}
