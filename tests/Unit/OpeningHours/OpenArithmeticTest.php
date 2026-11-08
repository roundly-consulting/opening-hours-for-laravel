<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use RoundlyConsulting\OpeningHours\Contracts\DynamicExceptionProvider;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\Exceptions\OpenTimeNotFoundException;
use RoundlyConsulting\OpeningHours\OpeningHours;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;

/*
 * The vectors below are copied from workflows-for-laravel's `tests/Unit/BusinessClockTest.php`
 * (its `BusinessClock` is the reference for "N business days" and "N open hours"). Keep the two
 * in lockstep: workflows switches its opening-hours calendar to these methods once released.
 * Its `WeekdayCalendar` (open all day on the listed ISO weekdays) is `openOn()` here.
 */

/**
 * @param  list<int>  $weekdays  ISO weekday numbers (1 = Monday)
 */
function openOn(string $timezone = 'Europe/Bratislava', array $weekdays = [1, 2, 3, 4, 5]): OpeningHours
{
    $names = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];
    $week = [];

    foreach ($weekdays as $weekday) {
        $week[$names[$weekday]] = ['00:00-24:00'];
    }

    return OpeningHours::make(['week' => $week], $timezone);
}

function nineToFive(string $timezone = 'UTC', array $exceptions = []): OpeningHours
{
    return OpeningHours::make([
        'week' => array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], ['09:00-17:00']),
        'exceptions' => $exceptions,
    ], $timezone);
}

afterEach(fn () => config()->set('opening-hours.search_days', 366));

describe('addOpenDays() (workflows BusinessClock vectors)', function (): void {
    it('adds open days at the same local time', function (string $from, int $days, string $expected): void {
        expect(openOn()->addOpenDays(at($from)->utc(), $days)->setTimezone('Europe/Bratislava')->format('D Y-m-d H:i'))->toBe($expected);
    })->with([
        'Fri + 2 → Tue' => ['2026-11-06 15:00', 2, 'Tue 2026-11-10 15:00'],
        'Sat + 2 → Tue' => ['2026-11-07 10:00', 2, 'Tue 2026-11-10 10:00'],
        'Mon + 1 → Tue' => ['2026-11-09 10:00', 1, 'Tue 2026-11-10 10:00'],
        'Mon + 5 → Mon' => ['2026-11-09 10:00', 5, 'Mon 2026-11-16 10:00'],
        'n = 0 is the start' => ['2026-11-07 10:00', 0, 'Sat 2026-11-07 10:00'],
    ]);

    // C-63: a DST gap moves forward by the gap; a repeated time takes its first occurrence.
    it('lands on a valid instant across DST', function (): void {
        // The US gap is Sun 2027-03-14: Fri 02:30 + 1 open day is Mon 02:30, which exists.
        $gap = openOn('America/New_York')->addOpenDays(at('2027-03-12 02:30', 'America/New_York')->utc(), 1);
        expect($gap->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe('2027-03-15 02:30');

        // Bratislava falls back on Sun 2026-10-25; Monday 02:30 exists once and is kept.
        $after = openOn()->addOpenDays(at('2026-10-23 02:30')->utc(), 1);
        expect($after->setTimezone('Europe/Bratislava')->format('Y-m-d H:i'))->toBe('2026-10-26 02:30');

        // A calendar whose open day starts in the gap hour: 2026-03-29 is the EU spring-forward day.
        $sunday = openOn('Europe/Bratislava', [7]);
        $landed = $sunday->addOpenDays(at('2026-03-22 02:30')->utc(), 1);
        expect($landed->setTimezone('Europe/Bratislava')->format('Y-m-d H:i'))->toBe('2026-03-29 03:30');

        // A calendar whose open day holds the repeated hour: the first occurrence (+02:00).
        $fallBack = $sunday->addOpenDays(at('2026-10-18 02:30')->utc(), 1);
        expect($fallBack->setTimezone('Europe/Bratislava')->format('Y-m-d H:i P'))->toBe('2026-10-25 02:30 +02:00');
    });

    // workflows' OwnerBriefTest: "escalates on an opening-hours calendar, skipping its holiday".
    it('skips a holiday', function (): void {
        $hours = nineToFive('Europe/Bratislava', [['date' => '2026-11-09']]);   // a holiday Monday

        expect($hours->addOpenDays(at('2026-11-06 10:00'), 1)->format('D Y-m-d H:i'))->toBe('Tue 2026-11-10 10:00')
            ->and($hours->addOpenDays(at('2026-11-06 10:00'), 2)->format('D Y-m-d H:i'))->toBe('Wed 2026-11-11 10:00');
    });
});

describe('addOpenTime() (workflows BusinessClock vectors)', function (): void {
    // C-64: open time only; an instant exactly at a close counts as closed.
    it('consumes only open time', function (): void {
        $hours = nineToFive();

        expect($hours->addOpenTime(CarbonImmutable::parse('2026-11-06 16:00', 'UTC'), CarbonInterval::seconds(7200))->format('D H:i'))->toBe('Mon 10:00')
            ->and($hours->addOpenTime(CarbonImmutable::parse('2026-11-09 17:00', 'UTC'), CarbonInterval::seconds(1800))->format('D H:i'))->toBe('Tue 09:30')
            ->and($hours->addOpenTime(CarbonImmutable::parse('2026-11-09 09:00', 'UTC'), CarbonInterval::seconds(3600))->format('D H:i'))->toBe('Mon 10:00')
            ->and($hours->addOpenTime(CarbonImmutable::parse('2026-11-09 09:00', 'UTC'), CarbonInterval::seconds(0))->format('D H:i'))->toBe('Mon 09:00');

        // A calendar open all day on weekdays.
        expect(openOn('UTC')->addOpenTime(CarbonImmutable::parse('2026-11-06 23:00', 'UTC'), CarbonInterval::seconds(7200))->format('D H:i'))->toBe('Mon 01:00');
    });

    it('skips a holiday', function (): void {
        $hours = nineToFive('Europe/Bratislava', [['date' => '2026-11-09']]);

        expect($hours->addOpenTime(at('2026-11-06 16:00'), CarbonInterval::hours(2))->format('D Y-m-d H:i'))->toBe('Tue 2026-11-10 10:00');
    });
});

// C-65: a calendar that is never open fails rather than searching forever.
it('refuses a calendar that is never open', function (string $method, int|CarbonInterval $amount): void {
    openOn('UTC', [])->{$method}(CarbonImmutable::parse('2026-11-09 09:00', 'UTC'), $amount);
})->with([
    'addOpenDays' => ['addOpenDays', 1],
    'addOpenTime' => ['addOpenTime', CarbonInterval::seconds(3600)],
])->throws(OpenTimeNotFoundException::class);

describe('the search window', function (): void {
    it('bounds addOpenDays by search_days after the start date', function (): void {
        $mondays = OpeningHours::make(['week' => ['monday' => ['09:00-17:00']]], 'UTC');
        $tuesday = CarbonImmutable::parse('2026-11-10 10:00', 'UTC');

        config()->set('opening-hours.search_days', 6);
        expect($mondays->addOpenDays($tuesday, 1)->format('D Y-m-d H:i'))->toBe('Mon 2026-11-16 10:00');

        config()->set('opening-hours.search_days', 5);

        try {
            $mondays->addOpenDays($tuesday, 1);
            $this->fail('Expected OpenTimeNotFoundException.');
        } catch (OpenTimeNotFoundException $exception) {
            expect($exception->searchDays)->toBe(5)
                ->and($exception->getMessage())->toContain('5 days')
                ->and($exception->getMessage())->toContain('opening-hours.search_days');
        }
    });

    it('bounds the whole span, not the gap between open days', function (): void {
        config()->set('opening-hours.search_days', 10);

        expect(fn () => openOn('UTC')->addOpenDays(CarbonImmutable::parse('2026-11-09 10:00', 'UTC'), 20))
            ->toThrow(OpenTimeNotFoundException::class);
    });

    it('bounds addOpenTime by search_days after the start date', function (): void {
        $mondays = OpeningHours::make(['week' => ['monday' => ['09:00-17:00']]], 'UTC');
        $tuesday = CarbonImmutable::parse('2026-11-10 10:00', 'UTC');

        config()->set('opening-hours.search_days', 6);
        expect($mondays->addOpenTime($tuesday, CarbonInterval::hours(8))->format('D Y-m-d H:i'))->toBe('Mon 2026-11-16 17:00');
        expect(fn () => $mondays->addOpenTime($tuesday, CarbonInterval::hours(9)))->toThrow(OpenTimeNotFoundException::class);

        config()->set('opening-hours.search_days', 5);
        expect(fn () => $mondays->addOpenTime($tuesday, CarbonInterval::minutes(1)))->toThrow(OpenTimeNotFoundException::class);
    });

    it('bounds an always-open calendar too', function (): void {
        config()->set('opening-hours.search_days', 3);
        $always = openOn('UTC', [1, 2, 3, 4, 5, 6, 7]);
        $from = CarbonImmutable::parse('2026-11-09 10:00', 'UTC');

        expect($always->addOpenTime($from, CarbonInterval::hours(50))->format('D Y-m-d H:i'))->toBe('Wed 2026-11-11 12:00')
            ->and(fn () => $always->addOpenTime($from, CarbonInterval::days(5)))->toThrow(OpenTimeNotFoundException::class);
    });
});

describe('calendar semantics', function (): void {
    it('counts an exception that opens a closed day', function (): void {
        $hours = nineToFive('UTC', [['date' => '2026-11-07', 'ranges' => ['10:00-12:00']]]);   // an open Saturday

        expect($hours->addOpenDays(CarbonImmutable::parse('2026-11-06 15:00', 'UTC'), 1)->format('D Y-m-d H:i'))->toBe('Sat 2026-11-07 15:00')
            ->and($hours->addOpenTime(CarbonImmutable::parse('2026-11-06 16:00', 'UTC'), CarbonInterval::hours(2))->format('D H:i'))->toBe('Sat 11:00');
    });

    it('honours dynamic exceptions', function (): void {
        $closesMonday = new class implements DynamicExceptionProvider
        {
            public function exceptionFor(LocalDate $date): ?ExceptionData
            {
                return $date->toDateString() === '2026-11-09' ? new ExceptionData(AbsoluteWindow::single($date), []) : null;
            }
        };

        $hours = nineToFive()->withDynamicExceptions($closesMonday);

        expect($hours->addOpenDays(CarbonImmutable::parse('2026-11-06 10:00', 'UTC'), 1)->format('D'))->toBe('Tue')
            ->and($hours->addOpenTime(CarbonImmutable::parse('2026-11-06 16:00', 'UTC'), CarbonInterval::hours(2))->format('D H:i'))->toBe('Tue 10:00');
    });

    it('consumes an overnight run across midnight', function (): void {
        $nights = OpeningHours::make(['week' => ['friday' => ['22:00-02:00'], 'saturday' => ['22:00-02:00']]], 'UTC');

        expect($nights->addOpenTime(CarbonImmutable::parse('2026-11-06 23:00', 'UTC'), CarbonInterval::hours(2))->format('D H:i'))->toBe('Sat 01:00')
            ->and($nights->addOpenTime(CarbonImmutable::parse('2026-11-06 23:00', 'UTC'), CarbonInterval::hours(4))->format('D H:i'))->toBe('Sat 23:00');
    });

    it('counts real elapsed open time across a DST change', function (): void {
        $sunday = openOn('Europe/Bratislava', [7]);

        // 2026-10-25 lasts 25 hours: 24 open hours from midnight end at 23:00.
        expect($sunday->addOpenTime(at('2026-10-25 00:00'), CarbonInterval::hours(24))->format('Y-m-d H:i'))->toBe('2026-10-25 23:00')
            // 2026-03-29 lasts 23 hours: the 24th open hour is the next Sunday's first.
            ->and($sunday->addOpenTime(at('2026-03-29 00:00'), CarbonInterval::hours(24))->format('Y-m-d H:i'))->toBe('2026-04-05 01:00');
    });

    it('reads the start date in the calendar timezone', function (): void {
        // Fri 23:30 in New York is already Saturday in Bratislava: + 1 open day → Monday 05:30.
        $from = at('2026-11-06 23:30', 'America/New_York');

        expect(openOn()->addOpenDays($from, 1)->format('D Y-m-d H:i'))->toBe('Mon 2026-11-09 05:30');
    });

    it('reports in the output timezone', function (): void {
        $hours = nineToFive('Europe/Bratislava')->withOutputTimezone('UTC');
        $friday = at('2026-11-06 10:00');

        expect($hours->addOpenDays($friday, 1)->getTimezone()->getName())->toBe('UTC')
            ->and($hours->addOpenDays($friday, 1)->format('D H:i'))->toBe('Mon 09:00')
            ->and($hours->addOpenTime($friday, CarbonInterval::hour())->getTimezone()->getName())->toBe('UTC')
            ->and($hours->addOpenDays($friday, 0)->getTimezone()->getName())->toBe('UTC')
            ->and($hours->addOpenTime($friday, CarbonInterval::seconds(0))->getTimezone()->getName())->toBe('UTC');
    });

    it('returns the start unchanged for zero or negative amounts', function (): void {
        $hours = nineToFive();
        $saturday = CarbonImmutable::parse('2026-11-07 10:00:00.250000', 'UTC');

        expect($hours->addOpenDays($saturday, -3)->equalTo($saturday))->toBeTrue()
            ->and($hours->addOpenTime($saturday, CarbonInterval::hours(2)->invert())->equalTo($saturday))->toBeTrue()
            ->and($hours->addOpenTime($saturday, CarbonInterval::make('PT0.5S') ?? CarbonInterval::seconds(0))->equalTo($saturday))->toBeTrue();
    });

    it('counts whole seconds and accepts any DateTimeInterface', function (): void {
        $hours = nineToFive();

        expect($hours->addOpenTime(new DateTimeImmutable('2026-11-09 09:00:00', new DateTimeZone('UTC')), CarbonInterval::make('PT1.9S') ?? CarbonInterval::seconds(0))->format('H:i:s'))->toBe('09:00:01')
            ->and($hours->addOpenDays(new DateTime('2026-11-09 09:15:42', new DateTimeZone('UTC')), 1)->format('D H:i:s'))->toBe('Tue 09:15:42');
    });
});
