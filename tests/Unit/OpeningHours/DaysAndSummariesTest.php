<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\OpeningHours\Contracts\DynamicExceptionProvider;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\Enums\DaySource;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\Exceptions\QueryRangeTooLargeException;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;

afterEach(fn () => CarbonImmutable::setTestNow());

function dynamicOn(string $date, array $ranges = [], ?string $label = null): DynamicExceptionProvider
{
    return new class($date, $ranges, $label) implements DynamicExceptionProvider
    {
        public function __construct(private string $date, private array $ranges, private ?string $label) {}

        public function exceptionFor(LocalDate $date): ?ExceptionData
        {
            if ($date->toDateString() !== $this->date) {
                return null;
            }

            return new ExceptionData(
                AbsoluteWindow::single($date),
                array_map(static fn (string $range): TimeRange => TimeRange::fromString($range), $this->ranges),
                $this->label,
            );
        }
    };
}

it('applies exception precedence: one-off, dynamic, yearly, schedule', function (): void {
    $hours = hours([
        'week' => ['thursday' => ['08:00-17:00'], 'friday' => ['08:00-17:00'], 'saturday' => ['08:00-17:00']],
        'exceptions' => [
            ['from' => '12-24', 'until' => '12-26', 'label' => 'Christmas'],
            ['date' => '2026-12-24', 'ranges' => ['10:00-12:00'], 'label' => 'Eve'],
        ],
    ])->withDynamicExceptions(dynamicOn('2026-12-25', ['10:00-11:00'], 'Dynamic'), dynamicOn('2026-12-24', ['07:00-08:00']));

    expect($hours->forDate('2026-12-24')->label)->toBe('Eve')
        ->and($hours->forDate('2026-12-24')->source)->toBe(DaySource::Exception)
        ->and($hours->forDate('2026-12-25')->source)->toBe(DaySource::Dynamic)
        ->and($hours->forDate('2026-12-25')->ranges[0]->toString('-'))->toBe('10:00-11:00')
        ->and($hours->forDate('2026-12-26')->label)->toBe('Christmas')
        ->and($hours->forDate('2026-12-26')->isClosed())->toBeTrue()
        ->and($hours->forDate('2026-12-31')->source)->toBe(DaySource::Schedule);
});

it('lets the narrowest exception win at each level', function (): void {
    $hours = hours([
        'exceptions' => [
            ['from' => '2026-12-20', 'until' => '2026-12-31', 'label' => 'Holidays'],
            ['from' => '2026-12-24', 'until' => '2026-12-25', 'ranges' => ['09:00-10:00'], 'label' => 'Short'],
            ['from' => '2026-06-01', 'until' => '2026-09-30', 'label' => 'Long'],
            ['from' => '2026-07-01', 'until' => '2026-08-31', 'ranges' => ['10:00-11:00'], 'label' => 'Longer but narrower'],
            ['from' => '11-01', 'until' => '11-30', 'label' => 'November'],
            ['date' => '11-15', 'ranges' => ['12:00-13:00'], 'label' => 'Mid'],
        ],
    ]);

    expect($hours->forDate('2026-12-24')->label)->toBe('Short')
        ->and($hours->forDate('2026-12-26')->label)->toBe('Holidays')
        ->and($hours->forDate('2026-07-15')->label)->toBe('Longer but narrower')
        ->and($hours->forDate('2026-06-15')->label)->toBe('Long')
        ->and($hours->forDate('2027-11-15')->label)->toBe('Mid')
        ->and($hours->forDate('2027-11-16')->label)->toBe('November');
});

it('selects schedules by priority and window, and closes when none applies', function (): void {
    $hours = hours([
        'schedules' => [
            ['label' => 'Regular', 'week' => ['monday' => ['09:00-17:00']]],
            ['label' => 'Summer', 'priority' => 10, 'window' => ['from' => '07-01', 'until' => '08-31'], 'week' => ['monday' => ['07:00-14:00']]],
            ['label' => 'New hours', 'window' => ['from' => '2026-11-01'], 'week' => ['monday' => ['09:00-18:00']]],
        ],
    ]);
    $summerOnly = hours(['schedules' => [['window' => ['from' => '07-01', 'until' => '08-31'], 'week' => ['monday' => ['07:00-14:00']]]]]);

    expect($hours->forDate('2026-09-28')->label)->toBe('Regular')
        ->and($hours->forDate('2026-07-06')->label)->toBe('Summer')
        ->and($hours->forDate('2026-11-02')->label)->toBe('New hours')
        ->and($hours->forDate('2027-07-05')->label)->toBe('Summer')
        ->and($summerOnly->forDate('2026-12-07')->source)->toBe(DaySource::None)
        ->and($summerOnly->forDate('2026-12-07')->isClosed())->toBeTrue()
        ->and($summerOnly->isAlwaysOpen())->toBeFalse();
});

it('keeps the overnight spill of the day before a closed exception', function (): void {
    $hours = hours([
        'week' => ['friday' => ['22:00-02:00']],
        'exceptions' => [['date' => '2026-10-03', 'label' => 'Closed Saturday']],
    ]);

    expect($hours->isOpenAt(at('2026-10-03 01:00')))->toBeTrue()
        ->and($hours->forDate('2026-10-03')->isClosed())->toBeTrue();
});

it('keeps a spill across a seasonal switch at midnight', function (): void {
    $hours = hours([
        'schedules' => [
            ['week' => ['sunday' => ['22:00-02:00'], 'monday' => ['09:00-10:00']]],
            ['window' => ['from' => '2026-11-02'], 'week' => ['tuesday' => ['09:00-10:00']]],
        ],
    ]);

    expect($hours->isOpenAt(at('2026-11-02 01:00')))->toBeTrue()
        ->and($hours->isOpenAt(at('2026-11-02 09:30')))->toBeFalse();
});

it('describes days, weekdays and weeks', function (): void {
    $hours = hours(['week' => ['monday' => ['08:00-12:00', '13:00-17:00'], 'saturday' => ['09:00-12:00']]]);
    CarbonImmutable::setTestNow(at('2026-09-26 12:00'));

    expect($hours->isOpenOn('monday'))->toBeTrue()
        ->and($hours->isClosedOn(Weekday::Sunday))->toBeTrue()
        ->and($hours->isOpenOnDate('2026-09-28'))->toBeTrue()
        ->and($hours->isClosedOnDate(at('2026-09-29 23:30')))->toBeTrue()
        ->and($hours->forWeekday('mon')->ranges)->toHaveCount(2)
        ->and($hours->forWeekday(Weekday::Monday)->date)->toBeNull()
        ->and($hours->forWeek()->for(Weekday::Saturday)->toString())->toBe('09:00–12:00')
        ->and(array_keys($hours->forWeek()->keyed()))->toBe(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
        ->and($hours->regularClosingDays())->toBe([Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday, Weekday::Sunday]);
});

it('takes the local date of instants in the calendar timezone', function (): void {
    $hours = hours(['week' => ['monday' => ['08:00-12:00']]]);

    // 23:30 UTC on Sunday is already Monday in Bratislava.
    expect($hours->forDate(iso('2026-09-27T23:30:00+00:00'))->weekday)->toBe(Weekday::Monday)
        ->and($hours->forDate(ld('2026-09-28'))->isOpen())->toBeTrue();
});

it('lists the dates of a week and of a period', function (): void {
    $hours = hours(['week' => ['monday' => ['08:00-12:00']]]);
    $week = $hours->forWeekOf('2026-09-30');

    expect(array_map(fn ($day) => $day->date?->toDateString(), $week))
        ->toBe(['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04'])
        ->and($hours->forPeriod('2026-09-28', '2026-10-10'))->toHaveCount(13)
        ->and($hours->forPeriod('2026-10-10', '2026-09-28'))->toBe([]);

    config()->set('opening-hours.first_day_of_week', 'sunday');

    expect($hours->forWeekOf('2026-09-30')[0]->date?->toDateString())->toBe('2026-09-27')
        ->and($hours->forWeek()->days()[0]->weekday)->toBe(Weekday::Sunday);
});

it('guards span queries with max_query_days', function (Closure $query): void {
    config()->set('opening-hours.max_query_days', 30);

    $query(hours(['week' => ['monday' => ['08:00-12:00']]]));
})->throws(QueryRangeTooLargeException::class)->with([
    'forPeriod' => [fn ($hours) => $hours->forPeriod('2026-01-01', '2026-03-01')],
    'openingPeriodsBetween' => [fn ($hours) => $hours->openingPeriodsBetween(at('2026-01-01'), at('2026-03-01'))],
    'exceptionalClosingDates' => [fn ($hours) => $hours->exceptionalClosingDates('2026-01-01', '2026-03-01')],
]);

it('reports the size of a too-large query', function (): void {
    config()->set('opening-hours.max_query_days', 10);

    try {
        hours([])->forPeriod('2026-01-01', '2026-01-31');
    } catch (QueryRangeTooLargeException $exception) {
        expect($exception->requestedDays)->toBe(31)->and($exception->maxDays)->toBe(10);
    }
});

it('measures spans in real elapsed time', function (): void {
    $hours = hours(['week' => ['monday' => ['08:00-12:00', '13:00-17:00'], 'tuesday' => ['08:00-12:00']]]);
    $from = at('2026-09-28 10:00');
    $to = at('2026-09-29 10:00');

    expect($hours->openSecondsBetween($from, $to))->toBe((2 + 4 + 2) * 3600)
        ->and($hours->closedSecondsBetween($from, $to))->toBe(16 * 3600)
        ->and($hours->openDurationBetween($from, $to)->totalHours)->toEqual(8)
        ->and($hours->openingPeriodsBetween($from, $to))->toHaveCount(3)
        ->and($hours->openingPeriodsBetween($from, $to)[0]->start->format('H:i'))->toBe('10:00')
        ->and($hours->openingPeriodsBetween($to, $from))->toBe([])
        ->and($hours->isOpenDuring(at('2026-09-28 13:00'), at('2026-09-28 17:00')))->toBeTrue()
        ->and($hours->isOpenDuring(at('2026-09-28 11:00'), at('2026-09-28 13:30')))->toBeFalse()
        ->and($hours->isOpenDuring(at('2026-09-28 12:30'), at('2026-09-28 13:30')))->toBeFalse()
        ->and($hours->isOpenDuring(at('2026-09-28 14:00'), at('2026-09-28 14:00')))->toBeFalse()
        ->and($hours->isOpenDuring(at('2026-10-04 12:00'), at('2026-10-04 13:00')))->toBeFalse()
        ->and($hours->isClosedDuring(at('2026-09-28 12:00'), at('2026-09-28 13:00')))->toBeTrue()
        ->and($hours->isClosedDuring(at('2026-09-28 11:00'), at('2026-09-28 13:00')))->toBeFalse()
        ->and($hours->isClosedDuring(at('2026-09-28 13:00'), at('2026-09-28 12:00')))->toBeTrue()
        ->and(hours([])->isClosedDuring(at('2026-09-28 13:00'), at('2026-09-28 14:00')))->toBeTrue();
});

it('lists exceptional closing dates and exceptions', function (): void {
    CarbonImmutable::setTestNow(at('2026-08-01 12:00'));
    $hours = hours([
        'week' => ['monday' => ['08:00-12:00']],
        'exceptions' => [
            ['date' => '12-25', 'label' => 'Christmas'],
            ['from' => '2026-08-03', 'until' => '2026-08-05', 'label' => 'Break'],
            ['date' => '2026-10-17', 'ranges' => ['10:00-12:00']],
        ],
    ]);

    expect(array_map(fn ($date) => $date->toDateString(), $hours->exceptionalClosingDates()))
        ->toBe(['2026-08-03', '2026-08-04', '2026-08-05', '2026-12-25'])
        ->and($hours->exceptionsBetween('2026-10-01', '2026-10-31'))->toHaveCount(1)
        ->and($hours->exceptionsBetween('2026-10-01', '2026-10-31')[0]->source)->toBe(DaySource::Exception)
        ->and($hours->exceptionalClosingDates('2026-12-01', '2026-12-31'))->toHaveCount(1);
});

it('knows when it is always closed and when it is not', function (): void {
    expect(hours(['week' => ['monday' => []], 'exceptions' => [['date' => '2026-01-01']]])->isAlwaysClosed())->toBeTrue()
        ->and(hours(['exceptions' => [['date' => '2026-01-01', 'ranges' => ['09:00-10:00']]]])->isAlwaysClosed())->toBeFalse()
        ->and(hours(['week' => ['monday' => ['09:00-10:00']]])->isAlwaysClosed())->toBeFalse()
        ->and(hours([])->withDynamicExceptions(dynamicOn('2026-01-01'))->isAlwaysClosed())->toBeFalse()
        ->and(hours(['week' => ['monday' => ['00:00-24:00']]])->isAlwaysOpen())->toBeFalse();
});

it('exposes its definition and a bounded day-plan memo', function (): void {
    $hours = hours(['week' => ['monday' => ['08:00-12:00']]]);
    $hours->forPeriod('2026-01-01', '2026-12-31');
    $hours->forPeriod('2027-01-01', '2027-12-31');

    expect($hours->timeline()->memoSize())->toBeLessThanOrEqual(512)
        ->and($hours->definition()->schedules)->toHaveCount(1)
        ->and($hours->compiled()->data)->toBe($hours->definition());
});
