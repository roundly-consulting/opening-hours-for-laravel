<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\Enums\Recurrence;
use RoundlyConsulting\OpeningHours\Enums\ViolationCode;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOpeningHoursException;
use RoundlyConsulting\OpeningHours\Validation\CalendarParser;
use RoundlyConsulting\OpeningHours\Validation\ParseOptions;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\YearlyWindow;

function violationsOf(array $payload, ?ParseOptions $options = null): array
{
    return array_map(
        fn ($violation) => [$violation->code, $violation->path],
        CalendarParser::parse($payload, $options ?? new ParseOptions)->violations->all(),
    );
}

it('parses the canonical example', function (): void {
    $data = CalendarData::fromArray([
        'timezone' => 'Europe/Bratislava',
        'label' => 'Reception',
        'schedules' => [
            [
                'id' => 12,
                'label' => 'Regular', 'priority' => 0, 'window' => null,
                'week' => [
                    'monday' => ['08:00-12:00', ['from' => '13:00', 'to' => '17:00', 'label' => 'Afternoon', 'capacity' => 2]],
                    'friday' => ['22:00-03:00'],
                    'sunday' => ['00:00-24:00'],
                    'saturday' => [],
                ],
            ],
            ['label' => 'Summer', 'priority' => 10, 'window' => ['from' => '07-01', 'until' => '08-31', 'recurrence' => 'yearly'], 'week' => ['monday' => ['07:00-14:00']]],
            ['label' => 'New hours', 'window' => ['from' => '2026-11-01'], 'week' => ['monday' => ['09:00-18:00']]],
        ],
        'exceptions' => [
            ['date' => '12-25', 'label' => 'Christmas'],
            ['from' => '12-24', 'until' => '01-02', 'recurrence' => 'yearly', 'label' => 'Holidays'],
            ['from' => '2026-08-03', 'until' => '2026-08-14', 'label' => 'Summer break'],
            ['date' => '2026-10-17', 'ranges' => ['10:00-12:00'], 'label' => 'Short day'],
        ],
        'meta' => ['source' => 'import'],
    ]);

    expect($data->timezone)->toBe('Europe/Bratislava')
        ->and($data->schedules)->toHaveCount(3)
        ->and($data->schedules[0]->id)->toBe(12)
        ->and($data->schedules[0]->week->for(Weekday::Monday)[1]->capacity)->toBe(2)
        ->and($data->schedules[1]->window)->toBeInstanceOf(YearlyWindow::class)
        ->and($data->schedules[2]->window)->toBeInstanceOf(AbsoluteWindow::class)
        ->and($data->baseSchedule()?->label)->toBe('Regular')
        ->and($data->exceptions[0]->recurrence())->toBe(Recurrence::Yearly)
        ->and($data->exceptions[2]->window->spanDays())->toBe(12)
        ->and($data->exceptions[3]->isClosed())->toBeFalse()
        ->and($data->meta)->toBe(['source' => 'import']);
});

it('round-trips through the canonical long form', function (): void {
    $payload = [
        'timezone' => 'Europe/Bratislava',
        'week' => ['monday' => ['08:00-12:00'], 'friday' => ['22:00-02:00']],
        'schedules' => [['priority' => 3, 'window' => ['from' => '12-01', 'until' => '01-31'], 'week' => ['tue' => ['09:00-10:00']]]],
        'exceptions' => [['date' => '2026-10-17', 'ranges' => [['from' => '10:00', 'to' => '12:00', 'label' => 'x', 'capacity' => 3, 'meta' => ['k' => 'v']]]]],
    ];

    $data = CalendarData::fromArray($payload);
    $again = CalendarData::fromArray($data->toArray());

    expect($again->toArray())->toBe($data->toArray())
        ->and(CalendarData::fromTrustedArray($data->toArray())->toArray())->toBe($data->toArray())
        ->and($data->toArray()['schedules'][0]['week']['sunday'])->toBe([])
        ->and($data->toArray(false))->not->toHaveKey('meta');
});

it('normalises empty windows to a base schedule', function (mixed $window): void {
    $data = CalendarData::fromArray(['schedules' => [['window' => $window, 'week' => ['monday' => ['09:00-10:00']]]]]);

    expect($data->baseSchedule())->not->toBeNull();
})->with([[null], [[]], [['from' => null, 'until' => null]], [['recurrence' => 'none']]]);

it('reports each violation code with its path', function (array $payload, ViolationCode $code, string $path, ?ParseOptions $options = null): void {
    expect(violationsOf($payload, $options))->toContain([$code, $path]);
})->with([
    'structure' => [['schedules' => 'nope'], ViolationCode::InvalidStructure, 'schedules'],
    'schedule not array' => [['schedules' => ['x']], ViolationCode::InvalidStructure, 'schedules.0'],
    'range object missing to' => [['week' => ['monday' => [['from' => '09:00']]]], ViolationCode::InvalidStructure, 'week.monday.0.to'],
    'range not a string or object' => [['week' => ['monday' => [42]]], ViolationCode::InvalidStructure, 'week.monday.0'],
    'invalid time' => [['week' => ['monday' => ['9:00-12:00']]], ViolationCode::InvalidTime, 'week.monday.0'],
    'invalid range string' => [['week' => ['monday' => ['0900']]], ViolationCode::InvalidTime, 'week.monday.0'],
    'invalid object time' => [['week' => ['monday' => [['from' => '09:00', 'to' => '25:00']]]], ViolationCode::InvalidTime, 'week.monday.0.to'],
    'empty range' => [['week' => ['monday' => ['12:00-12:00']]], ViolationCode::EmptyRange, 'week.monday.0'],
    'start at 24' => [['week' => ['monday' => ['24:00-02:00']]], ViolationCode::StartAt24, 'week.monday.0'],
    'overlap' => [['week' => ['monday' => ['09:00-12:00', '11:00-13:00']]], ViolationCode::Overlap, 'week.monday.1'],
    'circular overlap' => [['week' => ['sunday' => ['22:00-02:00'], 'monday' => ['01:00-05:00']]], ViolationCode::Overlap, 'week.sunday.0'],
    'exception overlap' => [['exceptions' => [['date' => '2026-01-01', 'ranges' => ['09:00-12:00', '11:00-13:00']]]], ViolationCode::Overlap, 'exceptions.0.ranges.1'],
    'invalid date' => [['exceptions' => [['date' => '2026-02-30']]], ViolationCode::InvalidDate, 'exceptions.0.date'],
    'invalid date type' => [['exceptions' => [['from' => 20260101]]], ViolationCode::InvalidDate, 'exceptions.0.from'],
    'invalid month day' => [['exceptions' => [['date' => '02-30']]], ViolationCode::InvalidMonthDay, 'exceptions.0.date'],
    'window inverted' => [['exceptions' => [['from' => '2026-02-01', 'until' => '2026-01-01']]], ViolationCode::WindowInverted, 'exceptions.0'],
    'recurrence mismatch mixed' => [['exceptions' => [['from' => '2026-02-01', 'until' => '03-01']]], ViolationCode::RecurrenceMismatch, 'exceptions.0'],
    'recurrence mismatch explicit' => [['exceptions' => [['date' => '2026-02-01', 'recurrence' => 'yearly']]], ViolationCode::RecurrenceMismatch, 'exceptions.0.recurrence'],
    'recurrence unknown' => [['exceptions' => [['date' => '2026-02-01', 'recurrence' => 'weekly']]], ViolationCode::InvalidStructure, 'exceptions.0.recurrence'],
    'yearly unbounded' => [['schedules' => [['window' => ['from' => '07-01'], 'week' => []]]], ViolationCode::YearlyWindowUnbounded, 'schedules.0.window'],
    'yearly without bounds' => [['schedules' => [['window' => ['recurrence' => 'yearly'], 'week' => []]]], ViolationCode::YearlyWindowUnbounded, 'schedules.0.window'],
    'duplicate base' => [['week' => ['monday' => ['09:00-10:00']], 'schedules' => [['week' => []]]], ViolationCode::DuplicateBaseSchedule, 'schedules.0'],
    'ambiguous schedules' => [['schedules' => [
        ['window' => ['from' => '07-01', 'until' => '08-31'], 'week' => []],
        ['window' => ['from' => '2026-08-01', 'until' => '2026-08-10'], 'week' => []],
    ]], ViolationCode::AmbiguousScheduleWindow, 'schedules.1'],
    'duplicate exception' => [['exceptions' => [['date' => '12-25'], ['date' => '12-25']]], ViolationCode::DuplicateException, 'exceptions.1'],
    'ambiguous exception' => [['exceptions' => [['from' => '2026-01-01', 'until' => '2026-01-02'], ['from' => '2026-01-02', 'until' => '2026-01-03']]], ViolationCode::AmbiguousException, 'exceptions.1'],
    'invalid timezone' => [['timezone' => '+02:00'], ViolationCode::InvalidTimezone, 'timezone'],
    'timezone required' => [[], ViolationCode::TimezoneRequired, 'timezone', new ParseOptions(requireTimezone: true)],
    'unknown weekday' => [['week' => ['funday' => ['09:00-10:00'], 0 => []]], ViolationCode::UnknownWeekday, 'week.funday'],
    'weekday zero' => [['week' => [0 => ['09:00-10:00']]], ViolationCode::UnknownWeekday, 'week.0'],
    'invalid priority' => [['schedules' => [['priority' => 5000, 'week' => []]]], ViolationCode::InvalidPriority, 'schedules.0.priority'],
    'invalid capacity' => [['week' => ['monday' => [['from' => '09:00', 'to' => '10:00', 'capacity' => 0]]]], ViolationCode::InvalidCapacity, 'week.monday.0.capacity'],
    'limit' => [['week' => ['monday' => array_map(fn ($h) => sprintf('%02d:00-%02d:30', $h, $h), range(0, 12))]], ViolationCode::LimitExceeded, 'week.monday'],
    'label' => [['label' => str_repeat('x', 192)], ViolationCode::LabelTooLong, 'label'],
    'meta' => [['meta' => ['blob' => str_repeat('x', 5000)]], ViolationCode::MetaTooLarge, 'meta'],
    'id' => [['schedules' => [['id' => 'x', 'week' => []]]], ViolationCode::InvalidStructure, 'schedules.0.id'],
    'duplicate schedule id' => [['schedules' => [['id' => 7, 'week' => []], ['id' => 7, 'window' => ['from' => '07-01', 'until' => '08-31'], 'week' => []]]], ViolationCode::DuplicateId, 'schedules.1.id'],
    'duplicate exception id' => [['exceptions' => [['id' => 7, 'date' => '2026-12-24'], ['id' => 7, 'date' => '2026-12-31']]], ViolationCode::DuplicateId, 'exceptions.1.id'],
]);

it('collects every violation, not only the first', function (): void {
    $violations = violationsOf([
        'timezone' => 'Nowhere/City',
        'week' => ['monday' => ['9:00-12:00', '12:00-12:00'], 'funday' => []],
        'exceptions' => [['date' => '2026-13-01']],
    ]);

    expect($violations)->toHaveCount(5);
});

it('enforces list limits before parsing the items', function (): void {
    config()->set('opening-hours.limits.exceptions', 2);
    config()->set('opening-hours.limits.schedules', 1);

    expect(violationsOf(['exceptions' => [['date' => '2026-01-01'], ['date' => '2026-01-02'], ['date' => '2026-01-03']]]))
        ->toBe([[ViolationCode::LimitExceeded, 'exceptions']])
        ->and(violationsOf(['schedules' => [['week' => []], ['window' => ['from' => '2026-01-01'], 'week' => []]]]))
        ->toBe([[ViolationCode::LimitExceeded, 'schedules']]);
});

it('rejects both date and from on an exception, and neither', function (): void {
    expect(violationsOf(['exceptions' => [['date' => '2026-01-01', 'from' => '2026-01-01']]]))->toBe([[ViolationCode::InvalidStructure, 'exceptions.0.date']])
        ->and(violationsOf(['exceptions' => [['label' => 'x']]]))->toBe([[ViolationCode::InvalidStructure, 'exceptions.0.date']])
        ->and(violationsOf(['exceptions' => [['until' => '2026-01-01']]]))->toBe([[ViolationCode::InvalidStructure, 'exceptions.0.from']])
        ->and(violationsOf(['exceptions' => ['x']]))->toBe([[ViolationCode::InvalidStructure, 'exceptions.0']])
        ->and(violationsOf(['exceptions' => [['date' => '2026-01-01', 'ranges' => 'x']]]))->toBe([[ViolationCode::InvalidStructure, 'exceptions.0.ranges']])
        ->and(violationsOf(['exceptions' => [['date' => '2026-01-01', 'label' => 5]]]))->toBe([[ViolationCode::InvalidStructure, 'exceptions.0.label']])
        ->and(violationsOf(['schedules' => [['window' => 'x', 'week' => []]]]))->toBe([[ViolationCode::InvalidStructure, 'schedules.0.window']])
        ->and(violationsOf(['schedules' => [['week' => 'x']]]))->toBe([[ViolationCode::InvalidStructure, 'schedules.0.week']])
        ->and(violationsOf(['week' => ['monday' => 5]]))->toBe([[ViolationCode::InvalidStructure, 'week.monday']])
        ->and(violationsOf(['meta' => 'x']))->toBe([[ViolationCode::InvalidStructure, 'meta']])
        ->and(violationsOf(['exceptions' => [['date' => '2026-01-01', 'ranges' => array_fill(0, 13, '09:00-10:00')]]]))->toBe([[ViolationCode::LimitExceeded, 'exceptions.0.ranges']]);
});

it('accepts a single string and null as day values', function (): void {
    $data = CalendarData::fromArray(['week' => ['monday' => '09:00-10:00', 'tuesday' => null, 1 => []]]);

    expect($data->baseSchedule()?->week->ranges)->toHaveCount(1);
});

it('throws with every violation from fromArray', function (): void {
    try {
        CalendarData::fromArray(['week' => ['monday' => ['9:00-12:00']], 'timezone' => 'x']);
        $this->fail('Expected InvalidOpeningHoursException');
    } catch (InvalidOpeningHoursException $exception) {
        expect($exception->violations())->toHaveCount(2)
            ->and($exception->getMessage())->toContain('(and 1 more)');
    }
});

it('merges overlapping ranges when asked', function (): void {
    $data = CalendarData::fromArray(
        ['week' => ['monday' => ['09:00-12:00', '11:00-13:00']], 'exceptions' => [['date' => '2026-01-01', 'ranges' => ['09:00-12:00', '10:00-11:00']]]],
        new ParseOptions(mergeOverlapping: true),
    );

    expect($data->baseSchedule()?->week->ranges[1][0]->toString('-'))->toBe('09:00-13:00')
        ->and($data->exceptions[0]->ranges)->toHaveCount(1);
});
