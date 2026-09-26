<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\OpeningHours\Availability\BusyPeriod;
use RoundlyConsulting\OpeningHours\Builders\ScheduleBuilder;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\Http\Resources\OpeningStatusResource;
use RoundlyConsulting\OpeningHours\OpeningHours;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\Clinic;

/**
 * The README's DX tour, executed as written, so the documentation cannot rot.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

it('runs the README tour', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-28 10:00', 'Europe/Bratislava'));
    $clinic = Clinic::query()->create(['timezone' => 'Europe/Bratislava']);

    // 2) Define (array sugar: top-level "week")
    $clinic->setOpeningHours([
        'timezone' => 'Europe/Bratislava',
        'week' => ['monday' => ['08:00-12:00', '13:00-17:00'], 'friday' => ['08:00-15:00']],
        'exceptions' => [['date' => '12-25', 'label' => 'Christmas']],
    ]);

    // 3) Query
    expect($clinic->openingHours()->isOpen())->toBeTrue()
        ->and($clinic->openingHours()->nextOpen()?->translatedFormat('l H:i'))->toBe('Monday 13:00');

    // 4) Detached (no DB)
    $preview = OpeningHours::make(['week' => ['saturday' => ['22:00-03:00']]], 'Europe/Bratislava');
    expect($preview->isOpenAt(CarbonImmutable::parse('2026-10-04 01:00', 'Europe/Bratislava')))->toBeTrue();

    // 5) List pages without N+1
    expect(Clinic::query()->withOpeningHours()->paginate()->total())->toBe(1);

    // 6) API
    expect(OpeningStatusResource::make($clinic->openingHours())->resolve()['is_open'])->toBeTrue();

    // 7) Bookable slots from any busy source
    $bookings = collect([BusyPeriod::make(
        CarbonImmutable::parse('2026-10-05 08:00', 'Europe/Bratislava'),
        CarbonImmutable::parse('2026-10-05 08:30', 'Europe/Bratislava'),
    )]);

    $slots = $clinic->openingHours()->availability()
        ->withBusyPeriods(fn (CarbonImmutable $s, CarbonImmutable $e) => $bookings->filter(fn (BusyPeriod $b) => $b->overlaps($s->getTimestamp(), $e->getTimestamp())))
        ->slots('2026-10-05', '2026-10-05')->duration(30)->get();

    expect($slots->first()?->start->format('H:i'))->toBe('08:30')
        ->and($slots)->toHaveCount(15);
});

it('runs the README builder example', function (): void {
    $clinic = Clinic::query()->create();

    $hours = $clinic->editOpeningHours()
        ->timezone('Europe/Bratislava')
        ->baseSchedule(fn (ScheduleBuilder $week) => $week
            ->weekdays('08:00-12:00', '13:00-17:00')
            ->saturday('09:00-12:00')
            ->closedOn(Weekday::Sunday))
        ->schedule(fn (ScheduleBuilder $week) => $week
            ->label('Summer')->yearly('07-01', '08-31')->priority(10)
            ->weekdays('07:00-14:00'))
        ->closed('2026-12-24', '2026-12-26', label: 'Christmas')
        ->closedYearly('01-01', label: 'New Year')
        ->exception('2026-10-17', ranges: ['10:00-12:00'], label: 'Short day')
        ->save();

    expect($hours->forDate('2026-07-06')->toString())->toBe('07:00–14:00')
        ->and($hours->forDate('2026-12-25')->label)->toBe('Christmas')
        ->and($hours->forDate('2027-01-01')->label)->toBe('New Year')
        ->and(array_map(fn ($group) => $group->label(), $hours->forWeek(CarbonImmutable::parse('2026-09-28'))->grouped()))
        ->toBe(['Mon–Fri', 'Sat', 'Sun']);
});
