<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\Enums\Recurrence;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidDateException;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\MonthDay;
use RoundlyConsulting\OpeningHours\ValueObjects\YearlyWindow;

it('parses month/days including Feb 29', function (): void {
    expect(md('02-29')->toString())->toBe('02-29')
        ->and((string) md('12-25'))->toBe('12-25')
        ->and(md('02-29')->existsIn(2026))->toBeFalse()
        ->and(md('02-29')->existsIn(2028))->toBeTrue()
        ->and(md('03-01')->dayOfLeapYear())->toBe(61)
        ->and(md('01-01')->compare(md('12-31')))->toBe(-1)
        ->and(md('01-01')->equals(new MonthDay(1, 1)))->toBeTrue()
        ->and(MonthDay::isValid('02-30'))->toBeFalse();
});

it('rejects bad month/days', function (string $value): void {
    MonthDay::fromString($value);
})->throws(InvalidDateException::class)->with(['02-30', '13-01', '1-1', '2026-01-01']);

it('rejects a bad constructed month/day', function (): void {
    new MonthDay(4, 31);
})->throws(InvalidDateException::class);

it('applies the Feb-29 rule in non-leap years', function (): void {
    expect(md('02-29')->toDateIn(2026)->toDateString())->toBe('2026-03-01')
        ->and(md('02-29')->toDateIn(2026, asEnd: true)->toDateString())->toBe('2026-02-28')
        ->and(md('02-29')->toDateIn(2028)->toDateString())->toBe('2028-02-29');
});

it('evaluates absolute windows', function (): void {
    $window = AbsoluteWindow::between(ld('2026-08-03'), ld('2026-08-14'));

    expect($window->contains(ld('2026-08-03')))->toBeTrue()
        ->and($window->contains(ld('2026-08-14')))->toBeTrue()
        ->and($window->contains(ld('2026-08-15')))->toBeFalse()
        ->and($window->spanDays())->toBe(12)
        ->and($window->recurrence())->toBe(Recurrence::None)
        ->and($window->toArray())->toBe(['from' => '2026-08-03', 'until' => '2026-08-14', 'recurrence' => 'none'])
        ->and(AbsoluteWindow::single(ld('2026-01-01'))->spanDays())->toBe(1)
        ->and((new AbsoluteWindow(ld('2026-11-01')))->spanDays())->toBeNull()
        ->and((new AbsoluteWindow(ld('2026-11-01')))->contains(ld('2099-01-01')))->toBeTrue()
        ->and((new AbsoluteWindow(null, ld('2026-11-01')))->contains(ld('1999-01-01')))->toBeTrue()
        ->and((new AbsoluteWindow)->isUnbounded())->toBeTrue();
});

it('rejects inverted absolute windows', function (): void {
    new AbsoluteWindow(ld('2026-02-01'), ld('2026-01-31'));
})->throws(InvalidDateException::class);

it('detects absolute overlaps including open ends', function (): void {
    $a = AbsoluteWindow::between(ld('2026-01-01'), ld('2026-01-10'));

    expect($a->overlaps(AbsoluteWindow::between(ld('2026-01-10'), ld('2026-01-20'))))->toBeTrue()
        ->and($a->overlaps(AbsoluteWindow::between(ld('2026-01-11'), ld('2026-01-20'))))->toBeFalse()
        ->and($a->overlaps(new AbsoluteWindow(null, ld('2026-01-01'))))->toBeTrue()
        ->and($a->overlaps(new AbsoluteWindow(ld('2026-02-01'))))->toBeFalse()
        ->and($a->overlaps(YearlyWindow::single(md('01-05'))))->toBeTrue()
        ->and($a->equals(AbsoluteWindow::between(ld('2026-01-01'), ld('2026-01-10'))))->toBeTrue()
        ->and($a->equals(YearlyWindow::single(md('01-05'))))->toBeFalse();
});

it('evaluates yearly windows including year wrap and Feb 29', function (): void {
    $summer = new YearlyWindow(md('06-01'), md('08-31'));
    $holidays = new YearlyWindow(md('12-24'), md('01-02'));
    $leapDay = YearlyWindow::single(md('02-29'));

    expect($summer->wraps())->toBeFalse()
        ->and($summer->contains(ld('2031-07-15')))->toBeTrue()
        ->and($summer->contains(ld('2031-09-01')))->toBeFalse()
        ->and($summer->spanDays())->toBe(92)
        ->and($holidays->wraps())->toBeTrue()
        ->and($holidays->contains(ld('2026-12-31')))->toBeTrue()
        ->and($holidays->contains(ld('2027-01-02')))->toBeTrue()
        ->and($holidays->contains(ld('2027-01-03')))->toBeFalse()
        ->and($holidays->spanDays())->toBe(10)
        ->and($leapDay->contains(ld('2028-02-29')))->toBeTrue()
        ->and($leapDay->contains(ld('2026-02-28')))->toBeFalse()
        ->and($leapDay->contains(ld('2026-03-01')))->toBeFalse()
        ->and((new YearlyWindow(md('02-29'), md('03-05')))->contains(ld('2026-03-01')))->toBeTrue()
        ->and((new YearlyWindow(md('02-01'), md('02-29')))->contains(ld('2026-02-28')))->toBeTrue()
        ->and((new YearlyWindow(md('01-02'), md('01-01')))->spanDays())->toBe(366)
        ->and($summer->recurrence())->toBe(Recurrence::Yearly)
        ->and($holidays->toArray())->toBe(['from' => '12-24', 'until' => '01-02', 'recurrence' => 'yearly']);
});

it('detects yearly overlaps', function (): void {
    $holidays = new YearlyWindow(md('12-24'), md('01-02'));

    expect($holidays->overlaps(new YearlyWindow(md('01-01'), md('01-05'))))->toBeTrue()
        ->and($holidays->overlaps(new YearlyWindow(md('06-01'), md('06-30'))))->toBeFalse()
        ->and($holidays->overlaps(AbsoluteWindow::between(ld('2026-06-01'), ld('2026-06-30'))))->toBeFalse()
        ->and($holidays->overlaps(AbsoluteWindow::between(ld('2026-12-30'), ld('2027-01-05'))))->toBeTrue()
        ->and($holidays->overlaps(new AbsoluteWindow(ld('2026-06-01'))))->toBeTrue()
        ->and($holidays->overlaps(AbsoluteWindow::between(ld('2026-01-03'), ld('2027-06-01'))))->toBeTrue()
        ->and($holidays->equals(new YearlyWindow(md('12-24'), md('01-02'))))->toBeTrue();
});
