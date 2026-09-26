<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\OpeningHours\Availability\Providers\EloquentBusyPeriodProvider;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidBusyPeriodException;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidTimezoneException;
use RoundlyConsulting\OpeningHours\Exceptions\TooManyBusyPeriodsException;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\Booking;

function bookings(): EloquentBusyPeriodProvider
{
    return EloquentBusyPeriodProvider::for(Booking::query()->where('status', '!=', 'cancelled'))
        ->columns(start: 'starts_at', end: 'ends_at')
        ->storedIn('UTC');
}

function busyBetween(EloquentBusyPeriodProvider $provider, string $from, string $to): array
{
    return array_map(
        fn ($period) => [$period->start->utc()->format('H:i'), $period->end->utc()->format('H:i'), $period->weight],
        [...$provider->busyPeriodsBetween(iso($from), iso($to))],
    );
}

it('reads overlapping rows, applying durations to open-ended ones', function (): void {
    Booking::query()->insert([
        ['starts_at' => '2026-09-28 08:00:00', 'ends_at' => '2026-09-28 09:00:00', 'duration_minutes' => null, 'seats' => null, 'status' => 'confirmed'],
        ['starts_at' => '2026-09-28 09:30:00', 'ends_at' => '2026-09-28 10:30:00', 'duration_minutes' => null, 'seats' => 2, 'status' => 'confirmed'],
        ['starts_at' => '2026-09-28 11:00:00', 'ends_at' => null, 'duration_minutes' => 45, 'seats' => null, 'status' => 'confirmed'],
        ['starts_at' => '2026-09-28 07:40:00', 'ends_at' => null, 'duration_minutes' => null, 'seats' => null, 'status' => 'confirmed'],
        ['starts_at' => '2026-09-28 09:45:00', 'ends_at' => '2026-09-28 10:00:00', 'duration_minutes' => null, 'seats' => null, 'status' => 'cancelled'],
        ['starts_at' => '2026-09-28 13:00:00', 'ends_at' => '2026-09-28 14:00:00', 'duration_minutes' => null, 'seats' => null, 'status' => 'confirmed'],
    ]);

    $provider = bookings()->durationColumn('duration_minutes')->defaultDuration(30)->weightColumn('seats')->maxNullEndMinutes(60);

    expect(busyBetween($provider, '2026-09-28T08:00:00+00:00', '2026-09-28T12:00:00+00:00'))->toBe([
        ['08:00', '09:00', 1],
        ['09:30', '10:30', 2],
        ['11:00', '11:45', 1],
        ['07:40', '08:10', 1],
    ]);
});

it('binds strings in the stored timezone and resolves fall-back values to the first occurrence', function (): void {
    // 02:30 local occurs twice on 2026-10-25 in Bratislava; the first is 00:30 UTC.
    Booking::query()->insert([
        ['starts_at' => '2026-10-25 02:30:00', 'ends_at' => '2026-10-25 03:30:00', 'duration_minutes' => null, 'seats' => null, 'status' => 'confirmed'],
    ]);

    $provider = EloquentBusyPeriodProvider::for(Booking::query())->columns('starts_at', 'test_bookings.ends_at')->storedIn('Europe/Bratislava');
    $periods = [...$provider->busyPeriodsBetween(iso('2026-10-25T00:00:00+00:00'), iso('2026-10-25T01:00:00+00:00'))];

    expect($periods)->toHaveCount(1)
        ->and($periods[0]->start->utc()->format('H:i'))->toBe('00:30')
        ->and($periods[0]->end->utc()->format('H:i'))->toBe('02:30')
        ->and([...$provider->busyPeriodsBetween(CarbonImmutable::parse('2026-10-25 04:00', 'Europe/Bratislava'), CarbonImmutable::parse('2026-10-25 05:00', 'Europe/Bratislava'))])->toBe([]);
});

it('feeds availability directly', function (): void {
    Booking::query()->insert([['starts_at' => '2026-09-28 08:00:00', 'ends_at' => '2026-09-28 09:00:00', 'duration_minutes' => null, 'seats' => null, 'status' => 'confirmed']]);

    $availability = hours(['week' => ['monday' => ['09:00-17:00']]])->availability()->at(at('2026-09-27 12:00'))->withBusyPeriods(bookings());

    expect($availability->isAvailable(at('2026-09-28 10:00'), at('2026-09-28 10:30')))->toBeFalse()
        ->and($availability->isAvailable(at('2026-09-28 11:00'), at('2026-09-28 11:30')))->toBeTrue();
});

it('rejects unsafe identifiers, bad durations and bad timezones', function (Closure $configure, string $exception): void {
    expect(fn () => $configure(EloquentBusyPeriodProvider::for(Booking::query())))->toThrow($exception);
})->with([
    [fn ($p) => $p->columns('starts_at; drop table x', 'ends_at'), InvalidBusyPeriodException::class],
    [fn ($p) => $p->durationColumn('a-b'), InvalidBusyPeriodException::class],
    [fn ($p) => $p->weightColumn('1abc'), InvalidBusyPeriodException::class],
    [fn ($p) => $p->defaultDuration(0), InvalidBusyPeriodException::class],
    [fn ($p) => $p->maxNullEndMinutes(0), InvalidBusyPeriodException::class],
    [fn ($p) => $p->storedIn('+02:00'), InvalidTimezoneException::class],
]);

it('caps the number of rows read', function (): void {
    config()->set('opening-hours.limits.busy_periods', 1);
    Booking::query()->insert([
        ['starts_at' => '2026-09-28 08:00:00', 'ends_at' => '2026-09-28 09:00:00', 'duration_minutes' => null, 'seats' => null, 'status' => 'confirmed'],
        ['starts_at' => '2026-09-28 09:00:00', 'ends_at' => '2026-09-28 10:00:00', 'duration_minutes' => null, 'seats' => null, 'status' => 'confirmed'],
    ]);

    busyBetween(bookings()->weightColumn(null)->durationColumn(null), '2026-09-28T00:00:00+00:00', '2026-09-29T00:00:00+00:00');
})->throws(TooManyBusyPeriodsException::class);
