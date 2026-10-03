<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\Models\Schedule;
use RoundlyConsulting\OpeningHours\Support\CalendarModel;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\Clinic;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

// The structural half of the seam; the behavioural proofs live in tests/ModelSwap.
it('declares each swappable model behind its config key', function (): void {
    expect(Calendar::class)->toBeSwappableVia('opening-hours.models.calendar')
        ->and(Schedule::class)->toBeSwappableVia('opening-hours.models.schedule')
        ->and(ExceptionRule::class)->toBeSwappableVia('opening-hours.models.exception_rule');
});

it('refuses a foreign model instead of falling back to the packaged one', function (): void {
    // The toolkit refuses any class that is not the packaged model or a subclass of it.
    config()->set('opening-hours.models.calendar', Clinic::class);

    expect(fn (): string => CalendarModel::class())->toThrow(
        InvalidConfigurationException::class,
        'Configuration value [opening-hours.models.calendar] must be a class-string of ['.Calendar::class.'], ['.Clinic::class.'] given.',
    );
});
