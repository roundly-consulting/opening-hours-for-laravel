<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\Enums\DaySource;
use RoundlyConsulting\OpeningHours\Enums\Recurrence;
use RoundlyConsulting\OpeningHours\Enums\UnavailableReason;
use RoundlyConsulting\OpeningHours\Enums\ViolationCode;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\Enums\WeekMode;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOpeningHoursException;

it('resolves weekdays from every accepted key', function (string|int $key, Weekday $expected): void {
    expect(Weekday::fromKey($key))->toBe($expected);
})->with([
    ['monday', Weekday::Monday],
    ['Monday', Weekday::Monday],
    ['MON', Weekday::Monday],
    ['sun', Weekday::Sunday],
    [7, Weekday::Sunday],
    ['3', Weekday::Wednesday],
]);

it('rejects unknown weekday keys, including 0', function (string|int $key): void {
    Weekday::fromKey($key);
})->throws(InvalidOpeningHoursException::class)->with([0, '0', 8, 'funday', '']);

it('rejects an unknown ISO number', function (): void {
    Weekday::fromIso(9);
})->throws(InvalidOpeningHoursException::class);

it('does weekday arithmetic', function (): void {
    expect(Weekday::Sunday->next())->toBe(Weekday::Monday)
        ->and(Weekday::Monday->previous())->toBe(Weekday::Sunday)
        ->and(Weekday::Saturday->isWeekend())->toBeTrue()
        ->and(Weekday::Friday->isWeekend())->toBeFalse()
        ->and(Weekday::Thursday->iso())->toBe(4)
        ->and(Weekday::Thursday->short())->toBe('thu')
        ->and(Weekday::fromDate(at('2026-09-26 12:00')))->toBe(Weekday::Saturday)
        ->and(Weekday::ordered(Weekday::Sunday)[0])->toBe(Weekday::Sunday)
        ->and(Weekday::ordered())->toHaveCount(7);
});

it('translates weekdays', function (): void {
    expect(Weekday::Monday->translated())->toBe('Monday')
        ->and(Weekday::Monday->translated(short: true))->toBe('Mon')
        ->and(Weekday::Sunday->translated('sk'))->toBe('Nedeľa')
        ->and(Weekday::Thursday->translated('sk', short: true))->toBe('Št');
});

it('uses the shared enum helpers', function (): void {
    expect(Weekday::options())->toHaveCount(7)
        ->and(Recurrence::values()->all())->toBe(['none', 'yearly'])
        ->and(DaySource::tryFromName('Dynamic'))->toBe(DaySource::Dynamic)
        ->and(WeekMode::hasValue('calendar_week'))->toBeTrue()
        ->and(ViolationCode::count())->toBe(26);
});

it('explains unavailability in each locale', function (): void {
    expect(UnavailableReason::Busy->message())->toBe('The requested time is already booked.')
        ->and(UnavailableReason::Closed->message('sk'))->toBe('V požadovanom čase je zatvorené.');

    foreach (UnavailableReason::cases() as $reason) {
        expect($reason->message())->not->toStartWith('opening-hours::')
            ->and($reason->message('sk'))->not->toStartWith('opening-hours::');
    }
});

it('translates every violation code in every locale', function (): void {
    foreach (ViolationCode::cases() as $code) {
        foreach (['en', 'sk'] as $locale) {
            expect(trans('opening-hours::validation.'.$code->value, [], $locale))->not->toStartWith('opening-hours::');
        }
    }
});
