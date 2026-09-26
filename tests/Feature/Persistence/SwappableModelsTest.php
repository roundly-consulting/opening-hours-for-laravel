<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\Models\Schedule;
use RoundlyConsulting\OpeningHours\Support\CalendarModel;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\Clinic;

// The structural half of the seam; the behavioural proofs live in tests/ModelSwap.
it('declares each swappable model behind its config key', function (): void {
    expect(Calendar::class)->toBeSwappableVia('opening-hours.models.calendar')
        ->and(Schedule::class)->toBeSwappableVia('opening-hours.models.schedule')
        ->and(ExceptionRule::class)->toBeSwappableVia('opening-hours.models.exception_rule');
});

it('falls back to the packaged model for a foreign model class', function (): void {
    config()->set('opening-hours.models.calendar', Clinic::class);

    expect(CalendarModel::class())->toBe(Calendar::class);
});
