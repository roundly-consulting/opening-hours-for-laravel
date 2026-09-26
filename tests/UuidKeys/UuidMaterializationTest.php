<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use RoundlyConsulting\OpeningHours\Actions\MaterializeIntervalsAction;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\UuidOwner;

it('correlates uuid owners with their materialized intervals', function (): void {
    Bus::fake();
    CarbonImmutable::setTestNow(at('2026-10-20 12:00', 'UTC'));
    config()->set('opening-hours.materialize.enabled', true);
    $owner = UuidOwner::query()->create();
    UuidOwner::query()->create();
    $owner->setOpeningHours(['timezone' => 'UTC', 'week' => ['monday' => ['09:00-10:00']]]);

    app(MaterializeIntervalsAction::class)->execute($owner->openingHoursCalendar(), ld('2026-10-19'), ld('2026-11-03'));

    expect(UuidOwner::query()->whereOpenAt(at('2026-10-26 09:30', 'UTC'))->pluck('id')->all())->toBe([$owner->id]);

    CarbonImmutable::setTestNow();
});
