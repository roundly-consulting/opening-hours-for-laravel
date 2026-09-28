<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\OpeningHours\Enums\DaySource;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidTimezoneException;
use RoundlyConsulting\OpeningHours\OpeningHours;

// 2026-09-28 is a Monday.
function officeHours(): OpeningHours
{
    return hours([
        'week' => [
            'monday' => ['08:00-12:00', '13:00-17:00'],
            'tuesday' => ['08:00-12:00', '13:00-17:00'],
            'wednesday' => ['08:00-12:00', '13:00-17:00'],
            'thursday' => ['08:00-12:00', '13:00-17:00'],
            'friday' => ['08:00-12:00', '13:00-17:00'],
            'saturday' => ['09:00-12:00'],
        ],
    ]);
}

afterEach(fn () => CarbonImmutable::setTestNow());

it('answers point-in-time questions with half-open periods', function (): void {
    $hours = officeHours();

    expect($hours->isOpenAt(at('2026-09-28 10:00')))->toBeTrue()
        ->and($hours->isOpenAt(at('2026-09-28 08:00')))->toBeTrue()
        ->and($hours->isOpenAt(at('2026-09-28 12:00')))->toBeFalse()
        ->and($hours->isClosedAt(at('2026-09-28 12:30')))->toBeTrue()
        ->and($hours->isOpenAt(at('2026-09-28 16:59:59')))->toBeTrue()
        ->and($hours->isOpenAt(at('2026-09-28 17:00')))->toBeFalse()
        ->and($hours->isOpenAt(at('2026-09-27 10:00')))->toBeFalse();
});

it('uses the clock for now', function (): void {
    CarbonImmutable::setTestNow(at('2026-09-28 10:00'));

    expect(officeHours()->isOpen())->toBeTrue()
        ->and(officeHours()->isClosed())->toBeFalse()
        ->and(officeHours()->nextClose()?->format('H:i'))->toBe('12:00');
});

it('navigates strictly forward', function (): void {
    $hours = officeHours();

    expect($hours->nextOpen(at('2026-09-28 12:30'))?->format('Y-m-d H:i'))->toBe('2026-09-28 13:00')
        ->and($hours->nextOpen(at('2026-09-28 10:00'))?->format('Y-m-d H:i'))->toBe('2026-09-28 13:00')
        ->and($hours->nextOpen(at('2026-09-28 08:00'))?->format('Y-m-d H:i'))->toBe('2026-09-28 13:00')
        ->and($hours->nextOpen(at('2026-10-03 12:00'))?->format('Y-m-d H:i'))->toBe('2026-10-05 08:00')
        ->and($hours->nextClose(at('2026-09-28 10:00'))?->format('Y-m-d H:i'))->toBe('2026-09-28 12:00')
        ->and($hours->nextClose(at('2026-09-28 12:00'))?->format('Y-m-d H:i'))->toBe('2026-09-28 17:00')
        ->and($hours->nextClose(at('2026-10-03 12:00'))?->format('Y-m-d H:i'))->toBe('2026-10-05 12:00');
});

it('navigates strictly backward', function (): void {
    $hours = officeHours();

    expect($hours->previousOpen(at('2026-09-28 10:00'))?->format('Y-m-d H:i'))->toBe('2026-09-28 08:00')
        ->and($hours->previousOpen(at('2026-09-28 08:00'))?->format('Y-m-d H:i'))->toBe('2026-09-26 09:00')
        ->and($hours->previousClose(at('2026-09-28 10:00'))?->format('Y-m-d H:i'))->toBe('2026-09-26 12:00')
        ->and($hours->previousClose(at('2026-09-28 12:00'))?->format('Y-m-d H:i'))->toBe('2026-09-26 12:00')
        ->and($hours->previousClose(at('2026-09-28 12:00:01'))?->format('Y-m-d H:i'))->toBe('2026-09-28 12:00');
});

it('walks back through a long closed stretch', function (): void {
    $hours = hours(['week' => ['monday' => ['09:00-10:00']], 'exceptions' => [['from' => '2026-06-01', 'until' => '2026-09-27']]]);

    expect($hours->previousOpen(at('2026-09-28 12:00'))?->format('Y-m-d H:i'))->toBe('2026-09-28 09:00')
        ->and($hours->previousOpen(at('2026-09-28 09:00'))?->format('Y-m-d H:i'))->toBe('2026-05-25 09:00')
        ->and($hours->previousClose(at('2026-09-28 09:30'))?->format('Y-m-d H:i'))->toBe('2026-05-25 10:00');
});

it('reports the current period and the defined range', function (): void {
    $hours = officeHours();
    $period = $hours->currentPeriod(at('2026-09-28 10:00'));
    $range = $hours->currentRange(at('2026-09-28 14:00'));

    expect($period?->start->format('H:i'))->toBe('08:00')
        ->and($period?->end->format('H:i'))->toBe('12:00')
        ->and($period?->source)->toBe(DaySource::Schedule)
        ->and($range?->toString('-'))->toBe('13:00-17:00')
        ->and($hours->currentPeriod(at('2026-09-28 12:30')))->toBeNull()
        ->and($hours->currentRange(at('2026-09-28 12:30')))->toBeNull()
        ->and($hours->nextPeriod(at('2026-09-28 10:00'))?->start->format('H:i'))->toBe('13:00');
});

it('coalesces touching ranges for navigation but not in the day view', function (): void {
    $hours = hours(['week' => ['monday' => ['09:00-12:00', '12:00-13:00']]]);

    expect($hours->nextClose(at('2026-09-28 10:00'))?->format('H:i'))->toBe('13:00')
        ->and($hours->currentPeriod(at('2026-09-28 12:30'))?->start->format('H:i'))->toBe('09:00')
        ->and($hours->forDate('2026-09-28')->ranges)->toHaveCount(2);
});

it('keeps a shared label and capacity when coalescing, and drops differing ones', function (): void {
    $same = hours(['week' => ['monday' => [
        ['from' => '09:00', 'to' => '12:00', 'label' => 'Clinic', 'capacity' => 2],
        ['from' => '12:00', 'to' => '13:00', 'label' => 'Clinic', 'capacity' => 2],
    ]]]);
    $different = hours(['week' => ['monday' => [
        ['from' => '09:00', 'to' => '12:00', 'label' => 'Clinic', 'capacity' => 2],
        ['from' => '12:00', 'to' => '13:00', 'label' => 'Surgery', 'capacity' => 1],
    ]]]);

    expect($same->currentPeriod(at('2026-09-28 10:00'))?->label)->toBe('Clinic')
        ->and($same->currentPeriod(at('2026-09-28 10:00'))?->capacity)->toBe(2)
        ->and($different->currentPeriod(at('2026-09-28 10:00'))?->label)->toBeNull()
        ->and($different->currentPeriod(at('2026-09-28 10:00'))?->capacity)->toBeNull()
        ->and($different->currentRange(at('2026-09-28 12:30'))?->label)->toBe('Surgery');
});

it('runs overnight ranges into the next day, including Sunday into Monday', function (): void {
    $hours = hours(['week' => ['friday' => ['22:00-02:00'], 'sunday' => ['22:00-03:00']]]);

    expect($hours->isOpenAt(at('2026-10-03 01:00')))->toBeTrue()
        ->and($hours->isOpenAt(at('2026-10-03 02:00')))->toBeFalse()
        ->and($hours->isOpenAt(at('2026-10-05 02:30')))->toBeTrue()
        ->and($hours->forDate('2026-10-03')->isClosed())->toBeTrue()
        ->and($hours->nextClose(at('2026-10-02 23:00'))?->format('Y-m-d H:i'))->toBe('2026-10-03 02:00')
        ->and($hours->currentRange(at('2026-10-05 01:00'))?->toString('-'))->toBe('22:00-03:00');
});

it('coalesces consecutive 24-hour days into one period', function (): void {
    $hours = hours(['week' => ['saturday' => ['00:00-24:00'], 'sunday' => ['00:00-24:00']]]);
    $period = $hours->currentPeriod(at('2026-10-03 12:00'));

    expect($period?->start->format('Y-m-d H:i'))->toBe('2026-10-03 00:00')
        ->and($period?->end->format('Y-m-d H:i'))->toBe('2026-10-05 00:00')
        ->and($period?->startsBeforeScan)->toBeFalse()
        ->and($period?->endsAfterScan)->toBeFalse();
});

it('never reports a scan edge as a real boundary', function (): void {
    $always = hours(['week' => ['monday' => ['00:00-24:00'], 'tuesday' => ['00:00-24:00'], 'wednesday' => ['00:00-24:00'],
        'thursday' => ['00:00-24:00'], 'friday' => ['00:00-24:00'], 'saturday' => ['00:00-24:00'], 'sunday' => ['00:00-24:00']]]);
    $period = $always->currentPeriod(at('2026-09-28 12:00'));

    expect($always->isAlwaysOpen())->toBeTrue()
        ->and($always->nextClose(at('2026-09-28 12:00')))->toBeNull()
        ->and($always->previousClose(at('2026-09-28 12:00')))->toBeNull()
        ->and($always->previousOpen(at('2026-09-28 12:00')))->toBeNull()
        ->and($always->nextOpen(at('2026-09-28 12:00')))->toBeNull()
        ->and($period?->startsBeforeScan)->toBeTrue()
        ->and($period?->endsAfterScan)->toBeTrue()
        ->and($period?->toArray()['end_unbounded'])->toBeTrue();
});

it('flags a 400-day open run longer than the search window', function (): void {
    $hours = hours(['exceptions' => [['from' => '2026-01-01', 'until' => '2027-02-04', 'ranges' => ['00:00-24:00']]]]);

    expect($hours->isAlwaysOpen())->toBeFalse()
        ->and($hours->nextClose(at('2026-01-02 12:00')))->toBeNull()
        ->and($hours->previousOpen(at('2027-02-03 12:00')))->toBeNull()
        ->and($hours->nextClose(at('2026-06-01 12:00'))?->format('Y-m-d H:i'))->toBe('2027-02-05 00:00')
        ->and($hours->previousOpen(at('2026-06-01 12:00'))?->format('Y-m-d H:i'))->toBe('2026-01-01 00:00')
        ->and($hours->currentPeriod(at('2026-01-02 12:00'))?->endsAfterScan)->toBeTrue();
});

it('returns null beyond the search window', function (): void {
    $hours = hours(['exceptions' => [['date' => '2027-06-01', 'ranges' => ['09:00-10:00']]]]);

    expect($hours->nextOpen(at('2026-09-26 12:00'), searchDays: 30))->toBeNull()
        ->and($hours->nextOpen(at('2026-09-26 12:00'))?->format('Y-m-d H:i'))->toBe('2027-06-01 09:00')
        ->and($hours->nextClose(at('2026-09-26 12:00'), searchDays: 30))->toBeNull()
        ->and($hours->previousOpen(at('2027-09-26 12:00'), searchDays: 30))->toBeNull()
        ->and($hours->previousClose(at('2027-09-26 12:00'), searchDays: 30))->toBeNull()
        ->and($hours->nextPeriod(at('2026-09-26 12:00'), searchDays: 30))->toBeNull();
});

it('searches exactly searchDays local days forward and backward', function (): void {
    $hours = hours(['week' => ['wednesday' => ['09:00-17:00'], 'friday' => ['22:00-02:00']]]);
    $monday = at('2026-09-28 12:00');

    expect($hours->nextOpen($monday, 1))->toBeNull()
        ->and($hours->nextPeriod($monday, 1))->toBeNull()
        ->and($hours->nextClose($monday, 1))->toBeNull()
        ->and($hours->nextOpen($monday, 2)?->format('Y-m-d H:i'))->toBe('2026-09-30 09:00')
        ->and($hours->nextClose($monday, 2)?->format('Y-m-d H:i'))->toBe('2026-09-30 17:00')
        ->and($hours->previousOpen(at('2026-09-25 12:00'), 1))->toBeNull()
        ->and($hours->previousOpen(at('2026-09-25 12:00'), 2)?->format('Y-m-d H:i'))->toBe('2026-09-23 09:00')
        ->and($hours->previousOpen($monday, 2))->toBeNull()
        ->and($hours->previousOpen($monday, 3)?->format('Y-m-d H:i'))->toBe('2026-09-25 22:00')
        ->and($hours->previousClose($monday, 2)?->format('Y-m-d H:i'))->toBe('2026-09-26 02:00')
        // A run that opens inside the window closes after it: the opening is found,
        // and the lookahead day still knows where it ends.
        ->and($hours->nextOpen(at('2026-10-01 12:00'), 1)?->format('Y-m-d H:i'))->toBe('2026-10-02 22:00')
        ->and($hours->nextClose(at('2026-10-02 23:00'), 1)?->format('Y-m-d H:i'))->toBe('2026-10-03 02:00');
});

it('treats an empty calendar as always closed', function (): void {
    $empty = OpeningHours::empty('UTC');

    expect($empty->isAlwaysClosed())->toBeTrue()
        ->and($empty->isAlwaysOpen())->toBeFalse()
        ->and($empty->nextOpen(at('2026-09-26 12:00')))->toBeNull()
        ->and($empty->currentPeriod(at('2026-09-26 12:00')))->toBeNull()
        ->and($empty->timezone()->getName())->toBe('UTC')
        ->and($empty->revision())->toBeNull();
});

it('reports instants in the output timezone without changing semantics', function (): void {
    $utc = officeHours()->withOutputTimezone('UTC');

    expect($utc->nextOpen(at('2026-09-28 12:30'))?->format('c'))->toBe('2026-09-28T11:00:00+00:00')
        ->and($utc->timezone()->getName())->toBe('Europe/Bratislava')
        ->and($utc->outputTimezone()->getName())->toBe('UTC')
        ->and($utc->isOpenAt(at('2026-09-28 10:00')))->toBeTrue();
});

it('resolves the timezone from the argument, the data, then config', function (): void {
    config()->set('opening-hours.timezone', 'Asia/Tokyo');

    expect(OpeningHours::make([])->timezone()->getName())->toBe('Asia/Tokyo')
        ->and(OpeningHours::make(['timezone' => 'America/New_York'])->timezone()->getName())->toBe('America/New_York')
        ->and(OpeningHours::make(['timezone' => 'America/New_York'], 'UTC')->timezone()->getName())->toBe('UTC');

    config()->set('opening-hours.timezone', null);
    config()->set('app.timezone', 'Europe/Vienna');

    expect(OpeningHours::make([])->timezone()->getName())->toBe('Europe/Vienna');
});

it('rejects fixed-offset and unknown timezones', function (string $timezone): void {
    OpeningHours::make([], $timezone);
})->throws(InvalidTimezoneException::class)->with(['+02:00', 'Mars/Olympus']);
