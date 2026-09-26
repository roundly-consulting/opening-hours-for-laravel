<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\Dynamic\EasterOffsetProvider;
use RoundlyConsulting\OpeningHours\Enums\DaySource;
use RoundlyConsulting\OpeningHours\Support\Easter;

it('computes Gregorian Easter Sunday', function (int $year, string $expected): void {
    expect(Easter::sunday($year)->toDateString())->toBe($expected);
})->with([[2024, '2024-03-31'], [2025, '2025-04-20'], [2026, '2026-04-05'], [2027, '2027-03-28'], [2038, '2038-04-25'], [2285, '2285-03-22']]);

it('closes or reopens movable holidays relative to Easter', function (): void {
    $hours = hours(['week' => ['friday' => ['09:00-17:00'], 'monday' => ['09:00-17:00'], 'sunday' => ['09:00-12:00']]])
        ->withDynamicExceptions(
            new EasterOffsetProvider(-2, 'Good Friday'),
            new EasterOffsetProvider(1, 'Easter Monday', ['10:00-12:00']),
        );

    expect($hours->forDate('2026-04-03')->isClosed())->toBeTrue()
        ->and($hours->forDate('2026-04-03')->label)->toBe('Good Friday')
        ->and($hours->forDate('2026-04-06')->toString(timeSeparator: '-'))->toBe('10:00-12:00')
        ->and($hours->forDate('2026-04-06')->source)->toBe(DaySource::Dynamic)
        ->and($hours->forDate('2026-04-05')->source)->toBe(DaySource::Schedule)
        ->and($hours->isAlwaysOpen())->toBeFalse();
});
