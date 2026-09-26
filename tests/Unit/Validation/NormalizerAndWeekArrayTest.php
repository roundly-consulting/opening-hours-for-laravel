<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ScheduleData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\WeekData;
use RoundlyConsulting\OpeningHours\Enums\ViolationCode;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOpeningHoursException;
use RoundlyConsulting\OpeningHours\Validation\DefinitionValidator;
use RoundlyConsulting\OpeningHours\Validation\ParseOptions;
use RoundlyConsulting\OpeningHours\Validation\RangeNormalizer;
use RoundlyConsulting\OpeningHours\Validation\Violation;
use RoundlyConsulting\OpeningHours\Validation\ViolationList;
use RoundlyConsulting\OpeningHours\Validation\WeekArrayParser;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;

function weekOf(array $days): WeekData
{
    $ranges = [];

    foreach ($days as $key => $values) {
        $ranges[Weekday::fromKey($key)->iso()] = array_map(fn (string $range) => TimeRange::fromString($range), $values);
    }

    return new WeekData($ranges);
}

function rangesText(WeekData $week): array
{
    $out = [];

    foreach ($week->ranges as $iso => $ranges) {
        $out[Weekday::fromIso($iso)->value] = array_map(fn (TimeRange $r) => $r->toString('-'), $ranges);
    }

    return $out;
}

it('merges overlapping and touching weekly ranges, keeping shared labels', function (): void {
    $week = new WeekData([1 => [
        TimeRange::fromString('09:00-12:00', 'A'),
        TimeRange::fromString('11:00-13:00', 'A'),
        TimeRange::fromString('13:00-14:00', 'A'),
        TimeRange::fromString('15:00-16:00', 'B'),
        TimeRange::fromString('15:30-17:00', 'C'),
    ]]);
    $merged = RangeNormalizer::week($week);

    expect(rangesText($merged))->toBe(['monday' => ['09:00-14:00', '15:00-17:00']])
        ->and($merged->ranges[1][0]->label)->toBe('A')
        ->and($merged->ranges[1][1]->label)->toBeNull();
});

it('merges across midnight and the Sunday wrap', function (): void {
    expect(rangesText(RangeNormalizer::week(weekOf(['sunday' => ['22:00-02:00'], 'monday' => ['01:00-05:00']]))))
        ->toBe(['sunday' => ['22:00-05:00']])
        ->and(rangesText(RangeNormalizer::week(weekOf(['monday' => ['00:00-24:00'], 'tuesday' => ['00:00-06:00', '05:00-08:00']]))))
        ->toBe(['monday' => ['00:00-24:00'], 'tuesday' => ['00:00-08:00']])
        ->and(rangesText(RangeNormalizer::week(weekOf(['monday' => ['12:00-24:00'], 'tuesday' => ['00:00-24:00'], 'wednesday' => ['00:00-06:00']]))))
        ->toBe(['monday' => ['12:00-24:00'], 'tuesday' => ['00:00-24:00'], 'wednesday' => ['00:00-06:00']])
        ->and(RangeNormalizer::week(new WeekData))->toEqual(new WeekData);
});

it('turns a fully covered week into seven all-day ranges', function (): void {
    $week = weekOf(['monday' => ['00:00-24:00', '12:00-13:00'], 'tuesday' => ['00:00-24:00'], 'wednesday' => ['00:00-24:00'], 'thursday' => ['00:00-24:00'],
        'friday' => ['00:00-24:00'], 'saturday' => ['00:00-24:00'], 'sunday' => ['00:00-24:00']]);

    expect(array_map(fn ($ranges) => count($ranges), RangeNormalizer::week($week)->ranges))->toBe([1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1]);
});

it('merges exception ranges and leaves an unrepresentable union to validation', function (): void {
    $merged = RangeNormalizer::day([TimeRange::fromString('09:00-12:00'), TimeRange::fromString('10:00-13:00')]);
    $tooLong = RangeNormalizer::day([TimeRange::fromString('00:00-24:00'), TimeRange::fromString('23:00-01:00')]);
    $overnight = RangeNormalizer::day([TimeRange::fromString('22:00-02:00'), TimeRange::fromString('23:00-03:00')]);

    expect(array_map(fn ($r) => $r->toString('-'), $merged))->toBe(['09:00-13:00'])
        ->and($tooLong)->toHaveCount(2)
        ->and(array_map(fn ($r) => $r->toString('-'), $overnight))->toBe(['22:00-03:00']);
});

it('validates DTO-built definitions', function (): void {
    $data = new CalendarData(
        timezone: 'Mars/Base',
        schedules: [new ScheduleData(weekOf(['monday' => ['09:00-12:00', '10:00-11:00']]), priority: 2000)],
    );

    $codes = array_map(fn (Violation $v) => $v->code, DefinitionValidator::validate($data)->all());

    expect($codes)->toContain(ViolationCode::InvalidTimezone, ViolationCode::InvalidPriority, ViolationCode::Overlap);
});

it('enforces counts, labels and meta on DTO input', function (): void {
    config()->set('opening-hours.limits.schedules', 1);
    config()->set('opening-hours.limits.exceptions', 1);
    config()->set('opening-hours.limits.ranges_per_day', 1);
    config()->set('opening-hours.limits.label_length', 3);

    $data = CalendarData::fromArray([])->withTimezone('UTC');
    $data = new CalendarData(
        schedules: [
            new ScheduleData(weekOf(['monday' => ['09:00-10:00', '11:00-12:00']]), label: 'long'),
            new ScheduleData(weekOf([]), new AbsoluteWindow(ld('2026-01-01'))),
        ],
        exceptions: [
            new ExceptionData(
                AbsoluteWindow::single(ld('2026-01-01')),
                [TimeRange::fromString('09:00-10:00', 'long'), TimeRange::fromString('11:00-12:00')],
            ),
            new ExceptionData(
                AbsoluteWindow::single(ld('2026-01-02')),
            ),
        ],
    );

    $paths = array_map(fn (Violation $v) => $v->code->value.'@'.$v->path, DefinitionValidator::validate($data)->all());

    expect($paths)->toContain(
        'limit_exceeded@schedules',
        'limit_exceeded@exceptions',
        'limit_exceeded@schedules.0.week.monday',
        'label_too_long@schedules.0.label',
        'limit_exceeded@exceptions.0.ranges',
        'label_too_long@exceptions.0.ranges.0.label',
    );
});

it('behaves as a collection of violations', function (): void {
    $list = new ViolationList([
        new Violation(ViolationCode::InvalidTime, 'week.monday.0', ['value' => 'x']),
        new Violation(ViolationCode::TimezoneRequired, ''),
    ]);

    expect($list)->toHaveCount(2)
        ->and($list->isEmpty())->toBeFalse()
        ->and($list->has(ViolationCode::InvalidTime))->toBeTrue()
        ->and($list->has(ViolationCode::Overlap))->toBeFalse()
        ->and(iterator_to_array($list))->toHaveCount(2)
        ->and($list->first()?->toArray())->toBe(['code' => 'invalid_time', 'path' => 'week.monday.0', 'params' => ['value' => 'x']])
        ->and($list->toMessageBag('opening_hours')->keys())->toBe(['opening_hours.week.monday.0', 'opening_hours'])
        ->and($list->toMessageBag()->first('week.monday.0'))->toBe('The week.monday.0 time is invalid; use HH:MM between 00:00 and 24:00.')
        ->and($list->toMessageBag(locale: 'sk')->first('opening_hours'))->toBe('Časové pásmo je povinné.')
        ->and((new ViolationList)->first())->toBeNull();
});

it('reads the legacy week-array format', function (): void {
    $data = CalendarData::fromWeekArray([
        'monday' => ['09:00-12:00', ['hours' => '13:00-18:00', 'data' => 'Afternoon']],
        'tuesday' => ['data' => 'Typical', '09:00-12:00'],
        'wednesday' => ['hours' => ['09:00-10:00', '11:00-12:00'], 'data' => ['room' => 'A']],
        'thursday' => '10:00-11:00',
        'Friday' => [],
        'exceptions' => [
            '2026-12-24' => [],
            '12-25' => ['data' => 'Christmas'],
            '2026-12-27 to 2026-12-30' => ['10:00-12:00'],
            '12-31 to 01-01' => [],
            '2026-10-17' => ['hours' => '10:00-12:00', 'data' => 'Short day'],
        ],
        'timezone' => 'Europe/Bratislava',
        'overflow' => true,
    ]);

    $week = $data->baseSchedule()?->week;

    expect($data->timezone)->toBe('Europe/Bratislava')
        ->and($week?->for(Weekday::Monday)[1]->label)->toBe('Afternoon')
        ->and($week?->for(Weekday::Tuesday)[0]->label)->toBe('Typical')
        ->and($week?->for(Weekday::Wednesday)[1]->meta)->toBe(['room' => 'A'])
        ->and($week?->for(Weekday::Thursday))->toHaveCount(1)
        ->and($week?->for(Weekday::Friday))->toBe([])
        ->and($data->exceptions)->toHaveCount(5)
        ->and($data->exceptions[1]->label)->toBe('Christmas')
        ->and($data->exceptions[2]->window->spanDays())->toBe(4)
        ->and($data->exceptions[3]->window->recurrence()->value)->toBe('yearly')
        ->and($data->exceptions[4]->label)->toBe('Short day')
        ->and($data->exceptions[4]->ranges[0]->label)->toBeNull();
});

it('rejects filters and maps violations back to legacy keys', function (): void {
    $result = WeekArrayParser::parse([
        'filters' => [fn () => true],
        'mon' => ['9:00-12:00'],
        'funday' => [],
        'exceptions' => ['2026-12-24' => ['10:00-25:00'], '2026-02-30' => []],
    ], new ParseOptions);

    expect(array_map(fn (Violation $v) => $v->code->value.'@'.$v->path, $result->violations->all()))->toBe([
        'unsupported_week_array_feature@filters',
        'unknown_weekday@funday',
        'invalid_time@mon.0',
        'invalid_time@exceptions.2026-12-24.0',
        'invalid_date@exceptions.2026-02-30.date',
    ])->and($result->isValid())->toBeFalse();

    expect(fn () => CalendarData::fromWeekArray(['exceptions' => 'x']))->toThrow(InvalidOpeningHoursException::class);
});

it('merges overlapping legacy ranges like the legacy read path', function (): void {
    $data = CalendarData::fromWeekArray(['monday' => ['09:00-12:00', '11:00-14:00']], new ParseOptions(mergeOverlapping: true));

    expect($data->baseSchedule()?->week->for(Weekday::Monday)[0]->toString('-'))->toBe('09:00-14:00');
});
