<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidDateException;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;

it('parses strict Y-m-d dates', function (): void {
    $date = LocalDate::fromString('2028-02-29');

    expect($date->toDateString())->toBe('2028-02-29')
        ->and((string) $date)->toBe('2028-02-29')
        ->and($date->isLeapYear())->toBeTrue()
        ->and($date->weekday())->toBe(Weekday::Tuesday);
});

it('rejects impossible or out-of-range dates', function (string $value): void {
    LocalDate::fromString($value);
})->throws(InvalidDateException::class)->with(['2026-02-29', '2026-13-01', '1899-12-31', '2201-01-01', '26-01-01', '2026-1-1', 'nope']);

it('rejects an impossible constructed date', function (): void {
    new LocalDate(2026, 2, 30);
})->throws(InvalidDateException::class);

it('does day arithmetic without drifting across DST', function (): void {
    $date = LocalDate::fromString('2026-03-28');

    expect($date->addDays(1)->toDateString())->toBe('2026-03-29')
        ->and($date->addDays(2)->toDateString())->toBe('2026-03-30')
        ->and($date->subDays(28)->toDateString())->toBe('2026-02-28')
        ->and($date->addDays(0))->toBe($date)
        ->and($date->diffInDays(LocalDate::fromString('2026-10-25')))->toBe(211)
        ->and(LocalDate::fromString('2000-01-01')->addDays(366)->toDateString())->toBe('2001-01-01')
        ->and(LocalDate::fromString('1970-01-01')->toEpochDay())->toBe(0)
        ->and(LocalDate::fromEpochDay(-1)->toDateString())->toBe('1969-12-31');
});

it('round-trips every day of four years through epoch days', function (): void {
    $date = LocalDate::fromString('2023-01-01');
    $carbon = CarbonImmutable::parse('2023-01-01', 'UTC');

    for ($i = 0; $i < 1461; $i++) {
        expect($date->toDateString())->toBe($carbon->toDateString())
            ->and($date->weekday()->iso())->toBe($carbon->dayOfWeekIso);

        $date = $date->addDays(1);
        $carbon = $carbon->addDay();
    }
});

it('takes the calendar date of an instant in a timezone', function (): void {
    $instant = CarbonImmutable::parse('2026-09-26 23:30:00', 'UTC');

    expect(LocalDate::fromDateTime($instant, new DateTimeZone('Europe/Bratislava'))->toDateString())->toBe('2026-09-27')
        ->and(LocalDate::fromDateTime($instant, new DateTimeZone('America/New_York'))->toDateString())->toBe('2026-09-26');
});

it('knows today from the clock', function (): void {
    CarbonImmutable::setTestNow('2026-09-26 22:30:00 UTC');

    expect(LocalDate::today(new DateTimeZone('Asia/Tokyo'))->toDateString())->toBe('2026-09-27');

    CarbonImmutable::setTestNow();
});

it('compares dates', function (): void {
    $a = LocalDate::fromString('2026-01-01');
    $b = LocalDate::fromString('2026-01-02');

    expect($a->compare($b))->toBe(-1)
        ->and($a->isBefore($b))->toBeTrue()
        ->and($b->isAfter($a))->toBeTrue()
        ->and($a->equals(LocalDate::fromString('2026-01-01')))->toBeTrue()
        ->and($a->monthDay()->toString())->toBe('01-01')
        ->and(LocalDate::isValid('2026-02-29'))->toBeFalse()
        ->and(LocalDate::isValid('2024-02-29'))->toBeTrue()
        ->and(LocalDate::isValid('x'))->toBeFalse()
        ->and(LocalDate::leap(1900))->toBeFalse()
        ->and(LocalDate::leap(2000))->toBeTrue();
});
