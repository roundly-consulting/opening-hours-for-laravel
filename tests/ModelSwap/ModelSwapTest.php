<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\Tests\Fixtures\Clinic;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\CustomCalendar;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\CustomExceptionRule;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\CustomSchedule;

it('creates the host calendar class through setOpeningHours', function (): void {
    expect('opening-hours.models.calendar')->toHonourModelSwap(CustomCalendar::class, function (): array {
        $clinic = Clinic::query()->create();
        $clinic->setOpeningHours(['week' => ['monday' => ['09:00-10:00']]]);

        return [$clinic->openingHoursCalendar(), ...$clinic->openingHoursCalendars()->get()->all()];
    });
});

it('creates the host schedule class through setOpeningHours', function (): void {
    expect('opening-hours.models.schedule')->toHonourModelSwap(CustomSchedule::class, function (): array {
        $clinic = Clinic::query()->create();
        $clinic->setOpeningHours(['week' => ['monday' => ['09:00-10:00']]]);

        return $clinic->openingHoursCalendar()?->schedules()->get()->all() ?? [];
    });
});

it('creates the host exception class through setOpeningHours', function (): void {
    expect('opening-hours.models.exception_rule')->toHonourModelSwap(CustomExceptionRule::class, function (): array {
        $clinic = Clinic::query()->create();
        $clinic->setOpeningHours(['exceptions' => [['date' => '2026-12-24']]]);

        return $clinic->openingHoursCalendar()?->exceptionRules()->get()->all() ?? [];
    });
});
