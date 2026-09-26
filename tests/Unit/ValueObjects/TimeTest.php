<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\Exceptions\InvalidTimeException;
use RoundlyConsulting\OpeningHours\ValueObjects\Time;

it('parses and formats wall-clock times', function (string $value, int $minutes): void {
    $time = Time::fromString($value);

    expect($time->minutes)->toBe($minutes)
        ->and($time->format())->toBe($value)
        ->and((string) $time)->toBe($value);
})->with([
    ['00:00', 0],
    ['09:30', 570],
    ['23:59', 1439],
    ['24:00', 1440],
]);

it('rejects malformed times', function (string $value): void {
    Time::fromString($value);
})->throws(InvalidTimeException::class)->with(['24:01', '9:00', 'ab', '25:00', '12:60', '', '12-00']);

it('rejects minutes outside the day', function (int $minutes): void {
    Time::fromMinutes($minutes);
})->throws(InvalidTimeException::class)->with([-1, 1441]);

it('exposes hours, minutes and comparisons', function (): void {
    $time = Time::fromString('13:45');

    expect($time->hours())->toBe(13)
        ->and($time->minute())->toBe(45)
        ->and($time->isMidnightEnd())->toBeFalse()
        ->and(Time::fromString('24:00')->isMidnightEnd())->toBeTrue()
        ->and($time->compare(Time::fromString('14:00')))->toBe(-1)
        ->and($time->equals(Time::fromMinutes(825)))->toBeTrue()
        ->and(Time::isValid('24:00'))->toBeTrue()
        ->and(Time::isValid('24:30'))->toBeFalse();
});
