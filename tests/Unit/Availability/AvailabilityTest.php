<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\OpeningHours\Availability\Availability;
use RoundlyConsulting\OpeningHours\Availability\AvailabilityResult;
use RoundlyConsulting\OpeningHours\Availability\BusyPeriod;
use RoundlyConsulting\OpeningHours\Availability\Providers\ArrayBusyPeriodProvider;
use RoundlyConsulting\OpeningHours\Availability\Providers\CompositeBusyPeriodProvider;
use RoundlyConsulting\OpeningHours\Availability\Providers\NullBusyPeriodProvider;
use RoundlyConsulting\OpeningHours\Availability\Slot;
use RoundlyConsulting\OpeningHours\Enums\UnavailableReason;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidBusyPeriodException;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidSlotQueryException;
use RoundlyConsulting\OpeningHours\Exceptions\QueryRangeTooLargeException;
use RoundlyConsulting\OpeningHours\Exceptions\TooManyBusyPeriodsException;

afterEach(fn () => CarbonImmutable::setTestNow());

// Monday 2026-09-28; "now" is the Sunday before.
function clinicAvailability(array $week = ['monday' => ['09:00-17:00'], 'tuesday' => ['09:00-17:00']]): Availability
{
    return hours(['week' => $week])->availability()->at(at('2026-09-27 12:00'));
}

function busy(string $from, string $to, int $weight = 1, ?string $reference = null): BusyPeriod
{
    return BusyPeriod::make(at($from), at($to), $weight, $reference);
}

it('checks a range and explains every refusal in order', function (): void {
    $availability = clinicAvailability()->withBusyPeriods([busy('2026-09-28 10:00', '2026-09-28 11:00', reference: 'appt-1')]);

    expect($availability->check(at('2026-09-28 09:00'), at('2026-09-28 09:30'))->available)->toBeTrue()
        ->and($availability->check(at('2026-09-28 09:30'), at('2026-09-28 09:00'))->reason)->toBe(UnavailableReason::InvalidRange)
        ->and($availability->check(at('2026-09-27 09:00'), at('2026-09-27 10:00'))->reason)->toBe(UnavailableReason::InPast)
        ->and($availability->minNotice(24 * 60)->check(at('2026-09-28 09:00'), at('2026-09-28 10:00'))->reason)->toBe(UnavailableReason::TooSoon)
        ->and($availability->horizon(0)->check(at('2026-09-28 09:00'), at('2026-09-28 10:00'))->reason)->toBe(UnavailableReason::TooFar)
        ->and($availability->check(at('2026-09-28 16:30'), at('2026-09-28 17:30'))->reason)->toBe(UnavailableReason::Closed)
        ->and($availability->check(at('2026-09-28 10:30'), at('2026-09-28 11:30'))->reason)->toBe(UnavailableReason::Busy)
        ->and($availability->check(at('2026-09-28 10:30'), at('2026-09-28 11:30'))->conflict?->reference)->toBe('appt-1')
        ->and($availability->check(at('2026-09-28 10:30'), at('2026-09-28 11:30'))->message())->toBe('The requested time is already booked.')
        ->and($availability->isAvailable(at('2026-09-28 11:00'), at('2026-09-28 12:00')))->toBeTrue();
});

it('uses capacity and weights', function (): void {
    $availability = clinicAvailability()->capacity(2)->withBusyPeriods([busy('2026-09-28 10:00', '2026-09-28 11:00')]);

    expect($availability->check(at('2026-09-28 10:00'), at('2026-09-28 10:30'))->remainingCapacity)->toBe(1)
        ->and($availability->isAvailable(at('2026-09-28 10:00'), at('2026-09-28 10:30')))->toBeTrue()
        ->and($availability->isAvailable(at('2026-09-28 10:00'), at('2026-09-28 10:30'), weight: 2))->toBeFalse()
        ->and($availability->isAvailable(at('2026-09-28 11:00'), at('2026-09-28 11:30'), weight: 2))->toBeTrue();
});

it('limits a booking spanning two range capacities by the smaller one', function (): void {
    $availability = hours(['week' => ['monday' => [
        ['from' => '09:00', 'to' => '12:00', 'capacity' => 3],
        ['from' => '12:00', 'to' => '13:00', 'capacity' => 1],
    ]]])->availability()->at(at('2026-09-27 12:00'));

    expect($availability->check(at('2026-09-28 11:30'), at('2026-09-28 12:30'))->remainingCapacity)->toBe(1)
        ->and($availability->isAvailable(at('2026-09-28 11:30'), at('2026-09-28 12:30'), weight: 2))->toBeFalse()
        ->and($availability->isAvailable(at('2026-09-28 10:30'), at('2026-09-28 11:30'), weight: 3))->toBeTrue();
});

it('keeps buffers inside opening hours by default', function (): void {
    $inside = clinicAvailability()->buffers(before: 15, after: 10);
    $outside = clinicAvailability()->buffers(before: 15, after: 10, withinOpeningHours: false)
        ->withBusyPeriods([busy('2026-09-28 08:30', '2026-09-28 08:50')]);

    expect($inside->check(at('2026-09-28 09:00'), at('2026-09-28 09:30'))->reason)->toBe(UnavailableReason::Closed)
        ->and($inside->isAvailable(at('2026-09-28 09:15'), at('2026-09-28 09:45')))->toBeTrue()
        ->and($inside->check(at('2026-09-28 16:30'), at('2026-09-28 16:55'))->reason)->toBe(UnavailableReason::Closed)
        ->and($outside->isAvailable(at('2026-09-28 09:05'), at('2026-09-28 09:30')))->toBeTrue()
        ->and($outside->check(at('2026-09-28 09:00'), at('2026-09-28 09:30'))->reason)->toBe(UnavailableReason::Busy);
});

it('gives a buffer outside opening hours the capacity of the range it adjoins', function (): void {
    $availability = hours(['week' => [
        'monday' => [['from' => '09:00', 'to' => '12:00', 'capacity' => 3], ['from' => '12:00', 'to' => '13:00', 'capacity' => 2]],
    ]])->availability()->at(at('2026-09-27 12:00'))->buffers(before: 15, after: 15, withinOpeningHours: false);
    $booked = $availability->withBusyPeriods([busy('2026-09-28 08:45', '2026-09-28 08:55', weight: 2), busy('2026-09-28 13:00', '2026-09-28 13:10')]);

    $opening = $availability->check(at('2026-09-28 09:00'), at('2026-09-28 10:00'), weight: 2);
    $closing = $availability->check(at('2026-09-28 12:00'), at('2026-09-28 13:00'), weight: 2);
    $slots = $availability->slots('2026-09-28', '2026-09-28')->duration(60)->step(60)->weight(2)->get();

    expect([$opening->available, $opening->reason, $opening->remainingCapacity])->toBe([true, null, 3])
        ->and([$closing->available, $closing->remainingCapacity])->toBe([true, 2])
        ->and($slots->first()->start->format('H:i'))->toBe('09:00')
        ->and($slots)->toHaveCount(4)
        ->and($booked->check(at('2026-09-28 09:00'), at('2026-09-28 10:00'))->remainingCapacity)->toBe(1)
        ->and($booked->isAvailable(at('2026-09-28 09:00'), at('2026-09-28 10:00'), weight: 2))->toBeFalse()
        ->and($booked->check(at('2026-09-28 12:00'), at('2026-09-28 13:00'), weight: 2)->reason)->toBe(UnavailableReason::Busy)
        ->and($booked->check(at('2026-09-28 12:00'), at('2026-09-28 13:00'))->remainingCapacity)->toBe(1);
});

it('generates aligned slots with limits and unavailable ones on request', function (): void {
    $availability = clinicAvailability()->withBusyPeriods([busy('2026-09-28 10:00', '2026-09-28 11:00')]);
    $slots = $availability->slots('2026-09-28', '2026-09-28')->duration(30)->step(30)->get();
    $all = $availability->slots('2026-09-28', '2026-09-28')->duration(30)->includeUnavailable()->get();

    expect($slots)->toHaveCount(14)
        ->and($slots->first()?->start->format('H:i'))->toBe('09:00')
        ->and($slots->last()?->start->format('H:i'))->toBe('16:30')
        ->and($all)->toHaveCount(16)
        ->and($all->available())->toHaveCount(14)
        ->and($all->firstWhere('available', false)?->reason)->toBe(UnavailableReason::Busy)
        ->and(array_keys($all->groupByDate()))->toBe(['2026-09-28'])
        ->and($all->toArray()[0])->toBe(['start' => '2026-09-28T09:00:00+02:00', 'end' => '2026-09-28T09:30:00+02:00', 'available' => true, 'remaining_capacity' => 1, 'reason' => null])
        ->and($availability->slots('2026-09-28', '2026-09-29')->duration(60)->limit(3)->get())->toHaveCount(3)
        ->and($availability->slots(at('2026-09-28 09:07'), at('2026-09-28 10:00'))->duration(15)->alignTo(15)->get()->first()?->start->format('H:i'))->toBe('09:15')
        ->and($availability->slots('2026-09-28', '2026-09-28')->duration(20)->step(40)->alignTo(20, anchor: 10)->first()?->start->format('H:i'))->toBe('09:10');
});

it('lets the before-buffer precede the notice limit', function (): void {
    $availability = hours(['week' => ['monday' => ['09:00-17:00']]])->availability()
        ->at(at('2026-09-28 09:00'))->minNotice(60)->buffers(before: 30);

    expect($availability->slots('2026-09-28', '2026-09-28')->duration(30)->first()?->start->format('H:i'))->toBe('10:00');
});

it('counts the horizon in wall-clock days across DST and includes its edge', function (): void {
    $availability = hours(['week' => ['sunday' => ['09:00-10:00'], 'monday' => ['09:00-10:00']]])->availability()
        ->at(at('2026-10-24 09:00'))->horizon(1);

    $slots = $availability->slots('2026-10-24', '2026-10-26')->duration(30)->get();

    expect($slots->map(fn (Slot $slot) => $slot->start->format('Y-m-d H:i'))->all())->toBe(['2026-10-25 09:00'])
        ->and($availability->check(at('2026-10-25 09:00'), at('2026-10-25 09:30'))->available)->toBeTrue()
        ->and($availability->check(at('2026-10-25 09:30'), at('2026-10-25 10:00'))->reason)->toBe(UnavailableReason::TooFar);
});

it('aligns slots to the local grid across a 30-minute shift', function (): void {
    $availability = hours(['week' => ['sunday' => ['01:00-04:00']]], 'Australia/Lord_Howe')->availability()->at(iso('2026-10-01T00:00:00+10:30'));

    $starts = $availability->slots('2026-10-04', '2026-10-04')->duration(30)->get()->map(fn (Slot $slot) => $slot->start->format('H:i'))->all();

    expect($starts)->toBe(['01:00', '01:30', '02:30', '03:00', '03:30']);
});

it('finds the next available slot across a closed weekend', function (): void {
    $availability = hours(['week' => ['friday' => ['09:00-10:00'], 'monday' => ['09:00-10:00']]])->availability()
        ->at(at('2026-10-02 09:31'))
        ->withBusyPeriods(fn (CarbonImmutable $from, CarbonImmutable $to) => [busy('2026-10-05 09:00', '2026-10-05 09:30')]);

    expect($availability->nextAvailableSlot(30)?->start->format('Y-m-d H:i'))->toBe('2026-10-05 09:30')
        ->and($availability->nextAvailableSlot(30, at('2026-10-10 00:00'), step: 15)?->start->format('Y-m-d H:i'))->toBe('2026-10-12 09:00')
        ->and($availability->horizon(2)->nextAvailableSlot(30))->toBeNull()
        ->and(hours([])->availability()->nextAvailableSlot(30))->toBeNull();
});

it('searches the next slot exactly search_days local days past today', function (): void {
    $availability = hours(['week' => ['wednesday' => ['09:00-10:00']]])->availability()->at(at('2026-09-28 12:00'));

    config()->set('opening-hours.search_days', 1);
    expect($availability->nextAvailableSlot(30))->toBeNull();

    config()->set('opening-hours.search_days', 2);
    expect($availability->nextAvailableSlot(30)?->start->format('Y-m-d H:i'))->toBe('2026-09-30 09:00');

    config()->set('opening-hours.search_days', 9);
    expect($availability->withBusyPeriods([busy('2026-09-30 09:00', '2026-09-30 10:00')])->nextAvailableSlot(30)?->start->format('Y-m-d H:i'))->toBe('2026-10-07 09:00');

    config()->set('opening-hours.search_days', 8);
    expect($availability->withBusyPeriods([busy('2026-09-30 09:00', '2026-09-30 10:00')])->nextAvailableSlot(30))->toBeNull();
});

it('finds the next slot in chunks that respect a small max_query_days, across DST', function (int $maxDays): void {
    config()->set('opening-hours.max_query_days', $maxDays);
    $everyDay = array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], ['09:00-10:00']);
    // Fully booked for two weeks around the October fall-back (25-hour day on 2026-10-25).
    $availability = hours(['week' => $everyDay])->availability()->at(at('2026-10-19 12:00'))
        ->withBusyPeriods([busy('2026-10-19 00:00', '2026-11-02 00:00')]);

    expect($availability->nextAvailableSlot(30)?->start->format('Y-m-d H:i'))->toBe('2026-11-02 09:00')
        ->and($availability->nextAvailableSlot(60, step: 45)?->start->format('Y-m-d H:i'))->toBe('2026-11-02 09:00');
})->with([1, 2, 5, 7]);

it('generates slots whose body runs days past the last start', function (): void {
    // Multi-day rentals: the scan must reach the end of the slot body, not just the last start.
    $allWeek = array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], ['00:00-24:00']);
    $availability = hours(['week' => $allWeek])->availability()->at(at('2026-09-27 12:00'));

    $week = $availability->slots('2026-09-28', '2026-09-28')->duration(7 * 24 * 60)->get();
    $threeDays = $availability->slots('2026-09-28', '2026-09-28')->duration(3 * 24 * 60)->step(60)->get();

    expect($availability->isAvailable(at('2026-09-28 00:00'), at('2026-10-05 00:00')))->toBeTrue()
        ->and($week->map(fn (Slot $slot) => $slot->start->format('Y-m-d H:i').' → '.$slot->end->format('Y-m-d H:i'))->all())
        ->toBe(['2026-09-28 00:00 → 2026-10-05 00:00'])
        ->and($threeDays)->toHaveCount(24)
        ->and($threeDays->last()?->end->format('Y-m-d H:i'))->toBe('2026-10-01 23:00');
});

it('restarts the slot grid at every local midnight', function (): void {
    $allWeek = array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], ['00:00-24:00']);
    $open = hours(['week' => $allWeek])->availability()->at(at('2026-09-27 12:00'));
    $overnight = hours(['week' => ['monday' => ['23:30-03:00']]])->availability()->at(at('2026-09-27 12:00'));
    $format = fn (Slot $slot): string => $slot->start->format('Y-m-d H:i');

    // A 100-minute grid does not divide the day: Tuesday 00:00 is its first line, not 01:40.
    expect($overnight->slots('2026-09-28', '2026-09-29')->duration(30)->step(100)->get()->map($format)->all())
        ->toBe(['2026-09-29 00:00', '2026-09-29 01:40'])
        // A grid longer than a day has one line a day (anchor 10:00): no day is skipped.
        ->and($open->slots('2026-09-28', '2026-09-30')->duration(60)->step(60)->alignTo(2 * 1440, anchor: 600)->get()->map($format)->all())
        ->toBe(['2026-09-28 10:00', '2026-09-29 10:00', '2026-09-30 10:00'])
        // A week-long rental is next available at the coming midnight, not a week later.
        ->and($open->nextAvailableSlot(7 * 24 * 60)?->start->format('Y-m-d H:i'))->toBe('2026-09-28 00:00')
        // An anchor that never falls inside a day leaves no grid line at all.
        ->and($open->slots('2026-09-28', '2026-09-30')->duration(60)->alignTo(3000, anchor: 2000)->get())->toHaveCount(0);

    // Santiago falls back at midnight (04-04 23:00–23:59 repeats): the repeated hour's lines
    // come before the restart, as the local wall clock reads them.
    $santiago = hours(['week' => ['saturday' => ['23:50-01:00']]], 'America/Santiago')->availability()->at(iso('2026-04-01T00:00:00-03:00'));

    expect($santiago->slots('2026-04-04', '2026-04-04')->duration(10)->step(15)->first()?->start->format('H:i P'))->toBe('23:00 -04:00');
});

it('starts the next-slot search at now when asked from the past', function (): void {
    // Everything before now is unbookable, so the search window must not be spent there.
    expect(clinicAvailability()->nextAvailableSlot(30, at('2025-01-01 00:00'))?->start->format('Y-m-d H:i'))->toBe('2026-09-28 09:00');
});

it('lists free periods', function (): void {
    $free = clinicAvailability()->capacity(2)
        ->withBusyPeriods([busy('2026-09-28 10:00', '2026-09-28 11:00', weight: 2), busy('2026-09-28 12:00', '2026-09-28 13:00')])
        ->freePeriods('2026-09-28', '2026-09-28', weight: 2);

    expect(array_map(fn ($period) => $period->start->format('H:i').'-'.$period->end->format('H:i'), $free))->toBe(['09:00-10:00', '11:00-12:00', '13:00-17:00']);
});

it('accepts every kind of busy source', function (): void {
    $array = new ArrayBusyPeriodProvider([busy('2026-09-28 10:00', '2026-09-28 11:00'), busy('2026-10-10 10:00', '2026-10-10 11:00')]);
    $composite = new CompositeBusyPeriodProvider($array, new NullBusyPeriodProvider);

    expect(clinicAvailability()->withBusyPeriods($composite)->isAvailable(at('2026-09-28 10:00'), at('2026-09-28 10:30')))->toBeFalse()
        ->and(iterator_to_array((new NullBusyPeriodProvider)->busyPeriodsBetween(at('2026-01-01'), at('2027-01-01'))))->toBe([])
        ->and(count($array->busyPeriodsBetween(at('2026-09-28'), at('2026-09-29'))))->toBe(1)
        ->and(fn () => new ArrayBusyPeriodProvider(['nope']))->toThrow(InvalidBusyPeriodException::class)
        ->and(fn () => clinicAvailability()->withBusyPeriods(fn () => ['nope'])->isAvailable(at('2026-09-28 10:00'), at('2026-09-28 10:30')))
        ->toThrow(InvalidBusyPeriodException::class);
});

it('caps busy periods per evaluation', function (): void {
    config()->set('opening-hours.limits.busy_periods', 2);
    $busy = [busy('2026-09-28 10:00', '2026-09-28 10:10'), busy('2026-09-28 10:10', '2026-09-28 10:20'), busy('2026-09-28 10:20', '2026-09-28 10:30')];

    clinicAvailability()->withBusyPeriods($busy)->check(at('2026-09-28 10:00'), at('2026-09-28 10:30'));
})->throws(TooManyBusyPeriodsException::class);

it('validates busy periods, settings and slot queries', function (Closure $call): void {
    $call();
})->throws(RuntimeException::class)->with([
    'inverted busy' => [fn () => busy('2026-09-28 10:00', '2026-09-28 10:00')],
    'zero weight' => [fn () => busy('2026-09-28 10:00', '2026-09-28 11:00', 0)],
    'capacity' => [fn () => clinicAvailability()->capacity(0)],
    'buffer' => [fn () => clinicAvailability()->buffers(before: -1)],
    'notice' => [fn () => clinicAvailability()->minNotice(-1)],
    'horizon' => [fn () => clinicAvailability()->horizon(-1)],
    'weight' => [fn () => clinicAvailability()->check(at('2026-09-28 10:00'), at('2026-09-28 11:00'), 0)],
    'no duration' => [fn () => clinicAvailability()->slots('2026-09-28', '2026-09-28')->get()],
    'bad duration' => [fn () => clinicAvailability()->slots('2026-09-28', '2026-09-28')->duration(0)],
    'bad step' => [fn () => clinicAvailability()->slots('2026-09-28', '2026-09-28')->step(0)],
    'bad align' => [fn () => clinicAvailability()->slots('2026-09-28', '2026-09-28')->alignTo(0)],
    'bad anchor' => [fn () => clinicAvailability()->slots('2026-09-28', '2026-09-28')->alignTo(15, -1)],
    'bad weight' => [fn () => clinicAvailability()->slots('2026-09-28', '2026-09-28')->weight(0)],
    'limit' => [fn () => clinicAvailability()->slots('2026-09-28', '2026-09-28')->limit(5000)],
    'span' => [fn () => clinicAvailability()->slots('2026-01-01', '2027-12-31')->duration(30)->get()],
]);

it('reports typed failures', function (): void {
    expect(fn () => clinicAvailability()->slots('2026-09-28', '2026-09-28')->get())->toThrow(InvalidSlotQueryException::class)
        ->and(fn () => clinicAvailability()->slots('2026-01-01', '2027-12-31')->duration(30)->get())->toThrow(QueryRangeTooLargeException::class)
        ->and(AvailabilityResult::ok(3)->message())->toBeNull()
        ->and(clinicAvailability()->slots('2026-09-20', '2026-09-21')->duration(30)->get())->toHaveCount(0);
});

it('keeps every collection method of a slot list working, and its json in the toArray shape', function (): void {
    $slots = clinicAvailability()->slots('2026-09-28', '2026-09-29')->duration(240)->alignTo(60)->get();

    expect($slots->map(fn (Slot $slot): string => $slot->start->format('H:i'))->toArray())->toBe(['09:00', '13:00', '09:00', '13:00'])
        ->and($slots->groupBy(fn (Slot $slot): string => $slot->start->format('Y-m-d'))->toArray()['2026-09-29'][1]['start'])->toBe('2026-09-29T13:00:00+02:00')
        ->and(array_keys($slots->keyBy(fn (Slot $slot): string => $slot->start->format('d H:i'))->toArray()))->toBe(['28 09:00', '28 13:00', '29 09:00', '29 13:00'])
        ->and($slots->chunk(3)->toArray()[1])->toBe([3 => $slots[3]->toArray()])
        ->and($slots->pluck('available')->toArray())->toBe([true, true, true, true])
        ->and(json_encode($slots))->toBe(json_encode($slots->toArray()))
        ->and(json_decode((string) json_encode($slots), true)[0])->toBe($slots->toArray()[0]);
});

it('guards the whole occupancy with max_query_days, not just the start window', function (Closure $query): void {
    $allWeek = array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], ['00:00-24:00']);

    $query(hours(['week' => $allWeek])->availability()->at(at('2026-09-27 12:00')));
})->throws(QueryRangeTooLargeException::class)->with([
    'duration past the limit' => [fn (Availability $a) => $a->slots('2026-09-28', '2026-09-28')->duration(400 * 1440)->get()],
    'overflowing duration' => [fn (Availability $a) => $a->slots('2026-09-28', '2026-09-28')->duration(intdiv(PHP_INT_MAX, 30))->get()],
    'overflowing buffer' => [fn (Availability $a) => $a->buffers(before: intdiv(PHP_INT_MAX, 30))->slots('2026-09-28', '2026-09-28')->duration(30)->get()],
    'next slot' => [fn (Availability $a) => $a->nextAvailableSlot(intdiv(PHP_INT_MAX, 30))],
    'check span' => [fn (Availability $a) => $a->check(at('2026-09-28 00:00'), at('2600-01-01 00:00'))],
    'check buffer' => [fn (Availability $a) => $a->buffers(after: intdiv(PHP_INT_MAX, 30))->check(at('2026-09-28 09:00'), at('2026-09-28 10:00'))],
    'isOpenDuring' => [fn (Availability $a) => hours([])->isOpenDuring(at('2026-09-28 00:00'), at('2600-01-01 00:00'))],
    'isClosedDuring' => [fn (Availability $a) => hours([])->isClosedDuring(at('2026-09-28 00:00'), at('2600-01-01 00:00'))],
]);
