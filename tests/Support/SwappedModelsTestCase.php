<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Tests\Support;

use RoundlyConsulting\OpeningHours\Tests\Fixtures\CustomCalendar;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\CustomExceptionRule;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\CustomSchedule;
use RoundlyConsulting\OpeningHours\Tests\TestCase;

/**
 * All three swappable models pointed at host subclasses BEFORE boot.
 */
abstract class SwappedModelsTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), [
            'opening-hours.models.calendar' => CustomCalendar::class,
            'opening-hours.models.schedule' => CustomSchedule::class,
            'opening-hours.models.exception_rule' => CustomExceptionRule::class,
        ]);
    }
}
