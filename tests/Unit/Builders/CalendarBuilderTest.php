<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\Builders\CalendarBuilder;
use RoundlyConsulting\OpeningHours\Builders\ScheduleBuilder;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ScheduleData;
use RoundlyConsulting\OpeningHours\Enums\ViolationCode;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOpeningHoursException;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;
use RoundlyConsulting\OpeningHours\ValueObjects\YearlyWindow;

it('builds the same definition as the equivalent array', function (): void {
    $built = CalendarBuilder::make()
        ->timezone('Europe/Bratislava')
        ->label('Reception')
        ->meta(['a' => 1])
        ->baseSchedule(fn (ScheduleBuilder $week) => $week
            ->weekdays('08:00-12:00', '13:00-17:00')
            ->saturday('09:00-12:00')
            ->closedOn(Weekday::Sunday))
        ->schedule(fn (ScheduleBuilder $week) => $week->label('Summer')->yearly('07-01', '08-31')->priority(10)->weekdays('07:00-14:00'))
        ->closed('2026-12-24', '2026-12-26', label: 'Christmas')
        ->closedYearly('01-01', label: 'New Year')
        ->exception('2026-10-17', ranges: ['10:00-12:00'], label: 'Short day')
        ->toData();

    $array = CalendarData::fromArray([
        'timezone' => 'Europe/Bratislava',
        'label' => 'Reception',
        'meta' => ['a' => 1],
        'schedules' => [
            ['week' => [
                'monday' => ['08:00-12:00', '13:00-17:00'], 'tuesday' => ['08:00-12:00', '13:00-17:00'], 'wednesday' => ['08:00-12:00', '13:00-17:00'],
                'thursday' => ['08:00-12:00', '13:00-17:00'], 'friday' => ['08:00-12:00', '13:00-17:00'], 'saturday' => ['09:00-12:00'],
            ]],
            ['label' => 'Summer', 'priority' => 10, 'window' => ['from' => '07-01', 'until' => '08-31'], 'week' => [
                'monday' => ['07:00-14:00'], 'tuesday' => ['07:00-14:00'], 'wednesday' => ['07:00-14:00'], 'thursday' => ['07:00-14:00'], 'friday' => ['07:00-14:00'],
            ]],
        ],
        'exceptions' => [
            ['from' => '2026-12-24', 'until' => '2026-12-26', 'label' => 'Christmas'],
            ['date' => '01-01', 'label' => 'New Year'],
            ['date' => '2026-10-17', 'ranges' => ['10:00-12:00'], 'label' => 'Short day'],
        ],
    ]);

    expect($built->toArray())->toBe($array->toArray());
});

it('replaces the base schedule but adds seasonal ones', function (): void {
    $builder = CalendarBuilder::make(CalendarData::fromArray([
        'schedules' => [['id' => 7, 'week' => ['monday' => ['09:00-10:00']]]],
    ]));

    $data = $builder
        ->baseSchedule(fn (ScheduleBuilder $week) => $week->everyDay('10:00-11:00'))
        ->schedule(new ScheduleData(window: new YearlyWindow(md('12-01'), md('12-31'))))
        ->schedule(function (ScheduleBuilder $week): void {
            $week->between('2027-01-01', LocalDate::fromString('2027-01-31'))->weekend('10:00-12:00')->meta(['x' => 1])->label('Jan');
        })
        ->toData();

    expect($data->schedules)->toHaveCount(3)
        ->and($data->schedules[0]->id)->toBe(7)
        ->and($data->schedules[0]->week->for(Weekday::Sunday)[0]->toString('-'))->toBe('10:00-11:00')
        ->and($data->schedules[2]->window?->toArray())->toBe(['from' => '2027-01-01', 'until' => '2027-01-31', 'recurrence' => 'none'])
        ->and($data->schedules[2]->week->for(Weekday::Saturday))->toHaveCount(1)
        ->and($data->schedules[2]->meta)->toBe(['x' => 1]);
});

it('supports every schedule builder shortcut', function (): void {
    $schedule = ScheduleBuilder::make()
        ->monday('08:00-09:00')->tuesday('08:00-09:00')->wednesday('08:00-09:00')->thursday('08:00-09:00')
        ->friday(TimeRange::fromString('08:00-09:00'))->saturday('08:00-09:00')->sunday('08:00-09:00')
        ->open24Hours(Weekday::Sunday)
        ->days([Weekday::Monday, Weekday::Tuesday], '10:00-11:00')
        ->from('2026-11-01')->until('2026-12-31')
        ->id(3)
        ->toData();

    expect($schedule->week->for(Weekday::Sunday)[0]->isAllDay())->toBeTrue()
        ->and($schedule->week->for(Weekday::Monday)[0]->toString('-'))->toBe('10:00-11:00')
        ->and($schedule->window?->toArray())->toBe(['from' => '2026-11-01', 'until' => '2026-12-31', 'recurrence' => 'none'])
        ->and($schedule->id)->toBe(3)
        ->and(ScheduleBuilder::fromData($schedule)->withoutWindow()->toData()->isBase())->toBeTrue()
        ->and(ScheduleBuilder::fromData($schedule)->toData()->window?->toArray())->toBe($schedule->window?->toArray());
});

it('removes schedules and exceptions by id, and rejects unknown ids', function (): void {
    $builder = CalendarBuilder::make(CalendarData::fromArray([
        'schedules' => [['id' => 1, 'week' => []], ['id' => 2, 'window' => ['from' => '2026-01-01'], 'week' => []]],
        'exceptions' => [['id' => 5, 'date' => '2026-01-01'], ['id' => 6, 'date' => '2026-01-02']],
    ]));

    $data = $builder->removeSchedule(2)->removeException(5)->toData();

    expect($data->schedules)->toHaveCount(1)
        ->and($data->exceptions)->toHaveCount(1)
        ->and($builder->withoutSchedules()->withoutExceptions()->toData()->schedules)->toBe([]);

    try {
        $builder->removeException(99);
        $this->fail('Expected an exception.');
    } catch (InvalidOpeningHoursException $exception) {
        expect($exception->violations()->first()?->code)->toBe(ViolationCode::UnknownId);
    }
});

it('reports violations and refuses to build an invalid definition', function (): void {
    $builder = CalendarBuilder::make()->baseSchedule(fn (ScheduleBuilder $week) => $week->monday('09:00-12:00', '11:00-13:00'));

    expect($builder->violations()->has(ViolationCode::Overlap))->toBeTrue()
        ->and(fn () => $builder->build())->toThrow(InvalidOpeningHoursException::class)
        ->and(fn () => $builder->save())->toThrow(InvalidOpeningHoursException::class)
        ->and($builder->mergeOverlapping()->build()->forDate('2026-09-28')->toString(timeSeparator: '-'))->toBe('09:00-13:00');
});

it('builds an in-memory calendar detached from any owner', function (): void {
    $hours = CalendarBuilder::make()
        ->timezone('Europe/Bratislava')
        ->baseSchedule(ScheduleBuilder::make()->monday('09:00-12:00')->toData())
        ->exception(LocalDate::fromString('2026-09-28'), ranges: [TimeRange::fromString('10:00-11:00')])
        ->exception('12-24', '12-26', yearly: true)
        ->expectRevision(3)
        ->ignoreConcurrentChanges()
        ->save();

    expect($hours->timezone()->getName())->toBe('Europe/Bratislava')
        ->and($hours->forDate('2026-09-28')->toString(timeSeparator: '-'))->toBe('10:00-11:00')
        ->and($hours->forDate('2026-12-25')->isClosed())->toBeTrue();
});
