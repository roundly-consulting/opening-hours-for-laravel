<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\Availability\Slot;
use RoundlyConsulting\OpeningHours\Engine\WallClock;

/**
 * The boundary rule, asserted with explicit offsets so a PHP/timelib change can
 * never silently move a boundary: a wall-clock `(date, minute)` resolves to the
 * FIRST instant at which the local clock reads a datetime at or after it.
 */
function boundaryIso(string $timezone, string $date, int $minute): string
{
    $zone = new DateTimeZone($timezone);
    $instant = WallClock::for($zone)->boundary(ld($date), $minute);

    return (new DateTimeImmutable('@'.$instant))->setTimezone($zone)->format('Y-m-d\TH:i:sP');
}

it('locates the transitions the DST cases rely on', function (string $timezone, string $date): void {
    $zone = new DateTimeZone($timezone);
    $start = (new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC')))->modify('-1 day')->getTimestamp();
    $transitions = $zone->getTransitions($start, $start + 3 * 86400);

    expect(count($transitions))->toBeGreaterThan(1);
})->with([
    ['Europe/Bratislava', '2026-03-29'],
    ['Europe/Bratislava', '2026-10-25'],
    ['America/New_York', '2026-03-08'],
    ['America/New_York', '2026-11-01'],
    ['Australia/Lord_Howe', '2026-04-05'],
    ['Australia/Lord_Howe', '2026-10-04'],
    ['America/Santiago', '2026-04-05'],
    ['America/Santiago', '2026-09-06'],
    ['Pacific/Apia', '2011-12-30'],
]);

it('clamps a spring-forward gap to the transition, not PHP\'s shifted time', function (): void {
    expect(boundaryIso('Europe/Bratislava', '2026-03-29', 150))->toBe('2026-03-29T03:00:00+02:00')
        ->and(boundaryIso('America/New_York', '2026-03-08', 150))->toBe('2026-03-08T03:00:00-04:00')
        ->and(boundaryIso('Australia/Lord_Howe', '2026-10-04', 135))->toBe('2026-10-04T02:30:00+11:00');
});

it('takes the first occurrence of an ambiguous fall-back time in every zone', function (): void {
    // PHP itself picks the LATER occurrence in Bratislava and the EARLIER in New York.
    expect(boundaryIso('Europe/Bratislava', '2026-10-25', 150))->toBe('2026-10-25T02:30:00+02:00')
        ->and(boundaryIso('America/New_York', '2026-11-01', 90))->toBe('2026-11-01T01:30:00-04:00')
        ->and(boundaryIso('Australia/Lord_Howe', '2026-04-05', 105))->toBe('2026-04-05T01:45:00+11:00');
});

it('resolves boundaries around a midnight transition', function (): void {
    expect(boundaryIso('America/Santiago', '2026-09-06', 0))->toBe('2026-09-06T01:00:00-03:00')
        ->and(boundaryIso('America/Santiago', '2026-09-05', 1440))->toBe('2026-09-06T01:00:00-03:00')
        ->and(boundaryIso('America/Santiago', '2026-04-04', 1410))->toBe('2026-04-04T23:30:00-03:00');
});

it('resolves every boundary of a skipped day to the next day\'s midnight', function (): void {
    expect(boundaryIso('Pacific/Apia', '2011-12-30', 0))->toBe('2011-12-31T00:00:00+14:00')
        ->and(boundaryIso('Pacific/Apia', '2011-12-30', 600))->toBe('2011-12-31T00:00:00+14:00')
        ->and(boundaryIso('Pacific/Apia', '2011-12-30', 1440))->toBe('2011-12-31T00:00:00+14:00');
});

it('needs no special casing for fractional offsets', function (): void {
    expect(boundaryIso('Asia/Kathmandu', '2026-06-15', 555))->toBe('2026-06-15T09:15:00+05:45');
});

it('converts instants back to local dates and minutes', function (): void {
    $clock = WallClock::for(new DateTimeZone('Europe/Bratislava'));
    $instant = at('2026-10-25 02:30:00')->getTimestamp();

    expect($clock->localDate($instant)->toDateString())->toBe('2026-10-25')
        ->and($clock->localMinute($instant))->toBe(150)
        ->and($clock->localSecondOfDay($instant))->toBe(9000)
        ->and($clock->timezone()->getName())->toBe('Europe/Bratislava')
        ->and(WallClock::for(new DateTimeZone('UTC'))->localEpochDay(-3600))->toBe(-1);
});

it('measures DST days in real time', function (): void {
    $hours = hours(['week' => ['sunday' => ['00:00-24:00']]]);

    expect($hours->openSecondsBetween(at('2026-03-29 00:00'), at('2026-03-30 00:00')))->toBe(23 * 3600)
        ->and($hours->openSecondsBetween(at('2026-10-25 00:00'), at('2026-10-26 00:00')))->toBe(25 * 3600);
});

it('resolves a range crossing the spring gap to one real hour', function (): void {
    $hours = hours(['week' => ['sunday' => ['01:00-02:30']]]);
    $period = $hours->currentPeriod(at('2026-03-29 01:30'));

    expect($period?->start->format('c'))->toBe('2026-03-29T01:00:00+01:00')
        ->and($period?->end->format('c'))->toBe('2026-03-29T03:00:00+02:00')
        ->and($period?->durationSeconds())->toBe(3600);
});

it('ends a range inside the fall-back overlap at the first occurrence', function (): void {
    $hours = hours(['week' => ['sunday' => ['01:00-02:30']]]);

    expect($hours->nextClose(at('2026-10-25 01:30'))?->format('c'))->toBe('2026-10-25T02:30:00+02:00')
        ->and($hours->isOpenAt(iso('2026-10-25T02:45:00+01:00')))->toBeFalse();
});

it('runs an overnight range across a transition', function (): void {
    $hours = hours(['week' => ['saturday' => ['22:00-04:00']]]);

    expect($hours->openSecondsBetween(at('2026-10-24 20:00'), at('2026-10-25 06:00')))->toBe(7 * 3600)
        ->and($hours->openSecondsBetween(at('2026-03-28 20:00'), at('2026-03-29 06:00')))->toBe(5 * 3600);
});

it('yields no periods on a skipped day', function (): void {
    $hours = hours(['week' => ['friday' => ['09:00-17:00']]], 'Pacific/Apia');

    expect($hours->openingPeriodsBetween(iso('2011-12-29T00:00:00-10:00'), iso('2012-01-02T00:00:00+14:00')))->toBe([])
        ->and($hours->forDate('2011-12-30')->ranges)->toHaveCount(1);
});

it('starts a midnight range at the midnight transition', function (): void {
    $hours = hours(['week' => ['sunday' => ['00:00-06:00']]], 'America/Santiago');

    expect($hours->nextOpen(iso('2026-09-05T12:00:00-04:00'))?->format('c'))->toBe('2026-09-06T01:00:00-03:00');
});

it('steps the slot grid on the local wall clock across DST transitions', function (): void {
    $allWeek = array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], ['00:00-24:00']);
    $open = hours(['week' => $allWeek])->availability()->at(at('2026-03-27 12:00'));
    $sunday = hours(['week' => ['sunday' => ['00:00-12:00']]])->availability();
    $format = fn (Slot $slot): string => $slot->start->format('Y-m-d H:i P');

    // A daily 10:00 line survives the 23-hour spring-forward day.
    expect($open->slots('2026-03-28', '2026-03-30')->duration(60)->alignTo(1440, 600)->get()->map($format)->all())
        ->toBe(['2026-03-28 10:00 +01:00', '2026-03-29 10:00 +02:00', '2026-03-30 10:00 +02:00'])
        // The first line after the gap is 04:00, not 08:00.
        ->and($sunday->at(at('2026-03-29 00:30'))->nextAvailableSlot(60, step: 240)?->start->format('H:i P'))->toBe('04:00 +02:00')
        // The repeated fall-back hour reads 02:00 again: a real line, not skipped.
        ->and($open->at(iso('2026-10-25T02:25:00+02:00'))->nextAvailableSlot(30, step: 120)?->start->format('H:i P'))->toBe('02:00 +01:00');
});

it('finds the next day\'s period when a fall-back after midnight repeats the day before', function (): void {
    // St. John's fell back at 00:01 on 2009-11-01: at 03:00Z the clock reads 23:30 on Saturday
    // again, while Sunday's 00:00 boundary already passed (02:30Z).
    $hours = hours(['week' => ['sunday' => ['00:00-06:00']]], 'America/St_Johns');
    $instant = iso('2009-11-01T03:00:00Z');

    expect($hours->isOpenAt($instant))->toBeTrue()
        ->and($hours->currentRange($instant)?->toString('-'))->toBe('00:00-06:00')
        ->and($hours->currentPeriod($instant)?->start->format('c'))->toBe('2009-11-01T00:00:00-02:30');
});
