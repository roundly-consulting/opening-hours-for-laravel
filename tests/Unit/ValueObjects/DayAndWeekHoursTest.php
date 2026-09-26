<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\Enums\DaySource;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\ValueObjects\DayHours;
use RoundlyConsulting\OpeningHours\ValueObjects\Period;
use RoundlyConsulting\OpeningHours\ValueObjects\StructuredData;
use RoundlyConsulting\OpeningHours\ValueObjects\Time;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;
use RoundlyConsulting\OpeningHours\ValueObjects\WeekHours;

function day(Weekday $weekday, string ...$ranges): DayHours
{
    return new DayHours(null, $weekday, array_values(array_map(fn (string $range) => TimeRange::fromString($range), $ranges)), DaySource::Schedule);
}

it('renders a day as text in each locale', function (): void {
    $open = day(Weekday::Monday, '09:00-12:00', '13:00-18:00');
    $closed = day(Weekday::Sunday);

    expect($open->toString())->toBe('09:00–12:00, 13:00–18:00')
        ->and((string) $open)->toBe('09:00–12:00, 13:00–18:00')
        ->and($closed->toString())->toBe('Closed')
        ->and($closed->toString(locale: 'sk'))->toBe('Zatvorené')
        ->and(day(Weekday::Monday, '00:00-24:00')->toString())->toBe('Open 24 hours')
        ->and(day(Weekday::Monday, '00:00-24:00')->toString(locale: 'sk'))->toBe('Otvorené nonstop');
});

it('renders the legacy byte-identical string with explicit separators', function (): void {
    expect(day(Weekday::Monday, '09:00-12:00', '13:00-18:00')->toString(',', '-', closedText: ''))->toBe('09:00-12:00,13:00-18:00')
        ->and(day(Weekday::Sunday)->toString(',', '-', closedText: ''))->toBe('')
        ->and(day(Weekday::Monday, '00:00-24:00')->toString(',', '-', closedText: ''))->toBe('00:00-24:00');
});

it('answers wall-clock questions about a day', function (): void {
    $day = day(Weekday::Friday, '09:00-12:00', '22:00-02:00');

    expect($day->isOpenAt(Time::fromString('10:00')))->toBeTrue()
        ->and($day->isOpenAt(Time::fromString('23:00')))->toBeTrue()
        ->and($day->isOpenAt(Time::fromString('01:00')))->toBeFalse()
        ->and($day->totalMinutes())->toBe(180 + 240)
        ->and($day->isOpenAllDay())->toBeFalse()
        ->and(day(Weekday::Friday, '00:00-12:00', '12:00-24:00')->isOpenAllDay())->toBeTrue()
        ->and(day(Weekday::Friday, '00:00-12:00', '13:00-24:00')->isOpenAllDay())->toBeFalse()
        ->and(day(Weekday::Friday, '00:00-12:00', '11:00-01:00')->isOpenAllDay())->toBeTrue();
});

it('serialises a day', function (): void {
    $day = new DayHours(ld('2026-12-24'), Weekday::Thursday, [TimeRange::fromString('10:00-12:00', meta: ['x' => 1])], DaySource::Exception, 'Eve', ['note' => 'y']);

    expect($day->toArray())->toBe([
        'date' => '2026-12-24',
        'weekday' => 'thursday',
        'weekday_label' => 'Thursday',
        'closed' => false,
        'all_day' => false,
        'source' => 'exception',
        'label' => 'Eve',
        'ranges' => [['from' => '10:00', 'to' => '12:00', 'label' => null, 'capacity' => null]],
        'text' => '10:00–12:00',
    ])->and($day->toArray(withMeta: true)['meta'])->toBe(['note' => 'y'])
        ->and($day->toArray(withMeta: true)['ranges'][0]['meta'])->toBe(['x' => 1]);
});

it('groups consecutive and matching weekdays', function (): void {
    $week = new WeekHours([
        day(Weekday::Monday, '08:00-17:00'),
        day(Weekday::Tuesday, '08:00-17:00'),
        day(Weekday::Wednesday, '08:00-17:00'),
        day(Weekday::Thursday, '08:00-12:00'),
        day(Weekday::Friday, '08:00-17:00'),
        day(Weekday::Saturday),
        day(Weekday::Sunday),
    ]);

    $consecutive = $week->grouped();
    $matching = $week->grouped(consecutiveOnly: false);

    expect(array_map(fn ($group) => $group->label(), $consecutive))->toBe(['Mon–Wed', 'Thu', 'Fri', 'Sat–Sun'])
        ->and($consecutive[3]->isClosed())->toBeTrue()
        ->and($consecutive[3]->hoursText())->toBe('Closed')
        ->and(array_map(fn ($group) => $group->label(), $matching))->toBe(['Mon, Tue, Wed, Fri', 'Thu', 'Sat–Sun'])
        ->and($matching[0]->toArray())->toBe([
            'days' => ['monday', 'tuesday', 'wednesday', 'friday'],
            'label' => 'Mon, Tue, Wed, Fri',
            'ranges' => [['from' => '08:00', 'to' => '17:00', 'label' => null, 'capacity' => null]],
            'text' => '08:00–17:00',
        ])
        ->and($consecutive[0]->label('sk'))->toBe('Po–St');
});

it('reorders, looks up and serialises a week', function (): void {
    $week = new WeekHours([day(Weekday::Monday, '08:00-17:00')]);

    expect($week->for(Weekday::Tuesday)->source)->toBe(DaySource::None)
        ->and($week->startingOn(Weekday::Sunday)->days()[0]->weekday)->toBe(Weekday::Sunday)
        ->and(array_keys($week->toArray()))->toBe(['monday'])
        ->and($week->toArray()['monday']['text'])->toBe('08:00–17:00');
});

it('models periods and structured data', function (): void {
    $period = new Period(at('2026-09-28 08:00'), at('2026-09-28 12:00'));
    $structured = new StructuredData([['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/Monday']]);

    expect($period->durationSeconds())->toBe(14400)
        ->and($period->contains(at('2026-09-28 08:00')))->toBeTrue()
        ->and($period->contains(at('2026-09-28 12:00')))->toBeFalse()
        ->and($period->overlaps(new Period(at('2026-09-28 11:00'), at('2026-09-28 13:00'))))->toBeTrue()
        ->and($period->overlaps(new Period(at('2026-09-28 12:00'), at('2026-09-28 13:00'))))->toBeFalse()
        ->and($period->toArray())->toBe(['start' => '2026-09-28T08:00:00+02:00', 'end' => '2026-09-28T12:00:00+02:00'])
        ->and($structured->toArray())->toHaveCount(1)
        ->and($structured->toJson())->toBe('[{"@type":"OpeningHoursSpecification","dayOfWeek":"https://schema.org/Monday"}]');
});

it('models opening periods', function (): void {
    $period = hours(['week' => ['monday' => ['08:00-12:00']]])->currentPeriod(at('2026-09-28 09:00'));

    expect($period?->durationSeconds())->toBe(14400)
        ->and($period?->contains(at('2026-09-28 11:59')))->toBeTrue()
        ->and($period?->toPeriod()->durationSeconds())->toBe(14400)
        ->and($period?->toArray())->toBe([
            'start' => '2026-09-28T08:00:00+02:00',
            'end' => '2026-09-28T12:00:00+02:00',
            'label' => null,
            'start_unbounded' => false,
            'end_unbounded' => false,
        ]);
});
