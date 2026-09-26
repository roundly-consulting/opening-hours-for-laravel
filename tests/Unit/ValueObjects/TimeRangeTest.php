<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\Enums\ViolationCode;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidTimeException;
use RoundlyConsulting\OpeningHours\ValueObjects\Time;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;

it('parses a plain range', function (): void {
    $range = TimeRange::fromString('09:00-17:30', 'Day', 3, ['room' => 'A']);

    expect($range->start->format())->toBe('09:00')
        ->and($range->end->format())->toBe('17:30')
        ->and($range->label)->toBe('Day')
        ->and($range->capacity)->toBe(3)
        ->and($range->meta)->toBe(['room' => 'A'])
        ->and($range->isOvernight())->toBeFalse()
        ->and($range->durationMinutes())->toBe(510)
        ->and($range->endOffsetMinutes())->toBe(1050)
        ->and($range->toString())->toBe('09:00–17:30')
        ->and($range->toString('-'))->toBe('09:00-17:30');
});

it('detects overnight ranges', function (): void {
    $range = TimeRange::fromString('22:00-02:00');

    expect($range->isOvernight())->toBeTrue()
        ->and($range->durationMinutes())->toBe(240)
        ->and($range->endOffsetMinutes())->toBe(1560);
});

it('accepts an en dash separator and spaces', function (): void {
    expect(TimeRange::fromString('08:00 – 12:00')->toString('-'))->toBe('08:00-12:00');
});

it('reads a 00:00 end as 24:00', function (): void {
    $range = TimeRange::fromString('22:00-00:00');

    expect($range->end->isMidnightEnd())->toBeTrue()
        ->and($range->isOvernight())->toBeFalse()
        ->and($range->durationMinutes())->toBe(120);
});

it('builds a whole day', function (): void {
    $range = TimeRange::allDay('Nonstop', 2);

    expect($range->isAllDay())->toBeTrue()
        ->and($range->durationMinutes())->toBe(1440)
        ->and($range->toArray())->toBe(['from' => '00:00', 'to' => '24:00', 'label' => 'Nonstop', 'capacity' => 2, 'meta' => null])
        ->and($range->toArray(false))->toBe(['from' => '00:00', 'to' => '24:00', 'label' => 'Nonstop', 'capacity' => 2]);
});

it('rejects empty ranges, a 24:00 start and bad capacities', function (Closure $make, ViolationCode $code): void {
    try {
        $make();
        $this->fail('Expected an InvalidTimeException.');
    } catch (InvalidTimeException $exception) {
        expect($exception->violationCode)->toBe($code);
    }
})->with([
    'empty' => [fn () => TimeRange::fromString('12:00-12:00'), ViolationCode::EmptyRange],
    'midnight empty' => [fn () => TimeRange::fromString('00:00-00:00'), ViolationCode::EmptyRange],
    'start at 24' => [fn () => new TimeRange(Time::fromString('24:00'), Time::fromString('02:00')), ViolationCode::StartAt24],
    'capacity zero' => [fn () => TimeRange::fromMinutes(0, 60, capacity: 0), ViolationCode::InvalidCapacity],
    'capacity too big' => [fn () => TimeRange::fromMinutes(0, 60, capacity: 1001), ViolationCode::InvalidCapacity],
    'malformed' => [fn () => TimeRange::fromString('09:00'), ViolationCode::InvalidTime],
]);

it('compares ranges by value', function (): void {
    expect(TimeRange::fromString('09:00-10:00')->equals(TimeRange::fromString('09:00-10:00')))->toBeTrue()
        ->and(TimeRange::fromString('09:00-10:00')->equals(TimeRange::fromString('09:00-10:00', 'x')))->toBeFalse();
});
