<?php

declare(strict_types=1);

it('builds schema.org items for the regular week and upcoming exceptions', function (): void {
    config()->set('opening-hours.api.upcoming_exceptions_days', 30);

    $hours = hours([
        'week' => ['monday' => ['09:00-17:00'], 'friday' => ['22:00-02:00'], 'sunday' => ['00:00-24:00']],
        'exceptions' => [
            ['date' => '2026-10-17', 'ranges' => ['10:00-12:00']],
            ['from' => '2026-10-20', 'until' => '2026-10-21', 'label' => 'Closed'],
            ['date' => '2026-12-24'],
            ['date' => '12-25'],
        ],
    ]);

    expect($hours->toStructuredData(at('2026-10-01 12:00'))->toArray())->toBe([
        ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/Monday', 'opens' => '09:00', 'closes' => '17:00'],
        ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/Friday', 'opens' => '22:00', 'closes' => '02:00'],
        ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/Sunday', 'opens' => '00:00', 'closes' => '23:59'],
        ['@type' => 'OpeningHoursSpecification', 'opens' => '10:00', 'closes' => '12:00', 'validFrom' => '2026-10-17', 'validThrough' => '2026-10-17'],
        ['@type' => 'OpeningHoursSpecification', 'opens' => '00:00', 'closes' => '00:00', 'validFrom' => '2026-10-20', 'validThrough' => '2026-10-21'],
    ]);
});

it('dates a seasonal schedule by its current or next occurrence', function (): void {
    $hours = hours([
        'schedules' => [
            ['week' => ['monday' => ['09:00-17:00']]],
            ['priority' => 5, 'window' => ['from' => '12-01', 'until' => '02-28'], 'week' => ['monday' => ['10:00-14:00']]],
            ['priority' => 5, 'window' => ['from' => '06-01', 'until' => '08-31'], 'week' => ['monday' => ['07:00-12:00']]],
            ['priority' => 7, 'window' => ['from' => '2027-05-01', 'until' => '2027-05-31'], 'week' => ['monday' => ['08:00-09:00']]],
            ['priority' => 6, 'window' => ['from' => '2027-04-01'], 'week' => ['tuesday' => ['08:00-09:00']]],
        ],
    ]);

    expect($hours->toStructuredData(at('2027-01-10 12:00'))->toArray()[0])->toMatchArray(['validFrom' => '2026-12-01', 'validThrough' => '2027-02-28'])
        ->and($hours->toStructuredData(at('2026-12-10 12:00'))->toArray()[0])->toMatchArray(['validFrom' => '2026-12-01', 'validThrough' => '2027-02-28'])
        ->and($hours->toStructuredData(at('2026-07-10 12:00'))->toArray()[0])->toMatchArray(['validFrom' => '2026-06-01', 'validThrough' => '2026-08-31', 'opens' => '07:00'])
        ->and($hours->toStructuredData(at('2027-05-10 12:00'))->toArray()[0])->toMatchArray(['validFrom' => '2027-05-01', 'validThrough' => '2027-05-31'])
        ->and($hours->toStructuredData(at('2027-04-10 12:00'))->toArray()[0])->toMatchArray(['validFrom' => '2027-04-01', 'dayOfWeek' => 'https://schema.org/Tuesday'])
        ->and($hours->toStructuredData(at('2026-10-10 12:00'))->toArray()[0])->not->toHaveKey('validFrom');
});
