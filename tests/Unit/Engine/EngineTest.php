<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ScheduleData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\WeekData;
use RoundlyConsulting\OpeningHours\Engine\Coalescer;
use RoundlyConsulting\OpeningHours\Engine\Compiler;
use RoundlyConsulting\OpeningHours\Engine\DayResolver;
use RoundlyConsulting\OpeningHours\Engine\PeriodTimeline;
use RoundlyConsulting\OpeningHours\Engine\RawPeriod;
use RoundlyConsulting\OpeningHours\Engine\WallClock;
use RoundlyConsulting\OpeningHours\Engine\WeekCoverage;
use RoundlyConsulting\OpeningHours\Enums\DaySource;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;
use RoundlyConsulting\OpeningHours\ValueObjects\YearlyWindow;

function week(array $days): WeekData
{
    $ranges = [];

    foreach ($days as $key => $values) {
        $ranges[Weekday::fromKey($key)->iso()] = array_map(fn (string $range) => TimeRange::fromString($range), $values);
    }

    return new WeekData($ranges);
}

it('orders schedules by priority, then windowed before base, then id', function (): void {
    $base = new ScheduleData(week(['monday' => ['09:00-10:00']]), label: 'base');
    $windowedLow = new ScheduleData(week([]), new AbsoluteWindow(ld('2026-01-01')), 0, 'windowed', id: 5);
    $windowedLowerId = new ScheduleData(week([]), new AbsoluteWindow(ld('2027-01-01')), 0, 'windowed-3', id: 3);
    $high = new ScheduleData(week([]), new YearlyWindow(md('07-01'), md('08-31')), 10, 'high');

    $definition = Compiler::compile(new CalendarData(schedules: [$base, $windowedLow, $high, $windowedLowerId]));

    expect(array_map(fn (ScheduleData $s) => $s->label, $definition->schedules))->toBe(['high', 'windowed-3', 'windowed', 'base'])
        ->and($definition->scheduleFor(ld('2026-07-15'))?->label)->toBe('high')
        ->and($definition->scheduleFor(ld('2026-09-15'))?->label)->toBe('windowed')
        ->and($definition->scheduleFor(ld('2025-09-15'))?->label)->toBe('base');
});

it('treats an unbounded window as a base schedule', function (): void {
    $schedule = new ScheduleData(week([]), new AbsoluteWindow);

    expect($schedule->isBase())->toBeTrue()
        ->and($schedule->toArray()['window'])->toBeNull()
        ->and($schedule->with(priority: 3)->priority)->toBe(3)
        ->and($schedule->with(label: 'x')->label)->toBe('x');
});

it('resolves the regular plan and closes without a schedule', function (): void {
    $resolver = new DayResolver(Compiler::compile(new CalendarData));

    expect($resolver->regular(ld('2026-09-28'))->source)->toBe(DaySource::None)
        ->and($resolver->plan(ld('2026-09-28'))->ranges)->toBe([]);
});

it('coalesces raw periods and merges touching ones', function (): void {
    $range = TimeRange::fromString('09:00-10:00', 'a');
    $other = TimeRange::fromString('10:00-11:00', 'b');
    $runs = Coalescer::coalesce([
        new RawPeriod(0, 100, $range, DaySource::Schedule, 0),
        new RawPeriod(100, 200, $other, DaySource::Schedule, 0),
        new RawPeriod(300, 400, $range, DaySource::Schedule, 0),
    ]);

    expect($runs)->toHaveCount(2)
        ->and($runs[0]->end)->toBe(200)
        ->and($runs[0]->label)->toBeNull()
        ->and($runs[1]->label)->toBe('a')
        ->and(Coalescer::coalesce([]))->toBe([]);
});

it('maps weekly ranges onto the circular week', function (): void {
    $full = week(['monday' => ['00:00-24:00'], 'tuesday' => ['00:00-24:00'], 'wednesday' => ['00:00-24:00'], 'thursday' => ['00:00-24:00'],
        'friday' => ['00:00-24:00'], 'saturday' => ['00:00-24:00'], 'sunday' => ['00:00-24:00']]);
    $overnightWrap = week(['sunday' => ['22:00-02:00'], 'monday' => ['01:00-03:00', '09:00-10:00']]);

    expect(WeekCoverage::coversWholeWeek($full))->toBeTrue()
        ->and(WeekCoverage::coveredMinutes(week([])))->toBe(0)
        ->and(WeekCoverage::coveredMinutes($overnightWrap))->toBe(240 + 60 + 60)
        ->and(WeekCoverage::segments($overnightWrap))->toHaveCount(4)
        ->and(WeekCoverage::overlaps($overnightWrap))->toHaveCount(1)
        ->and(WeekCoverage::overlaps($overnightWrap)[0][0])->toBe([Weekday::Monday, 0])
        ->and(WeekCoverage::overlaps(week(['monday' => ['09:00-12:00', '12:00-13:00']])))->toBe([]);
});

it('keeps the plan memo bounded (LRU)', function (): void {
    $timeline = new PeriodTimeline(WallClock::for(new DateTimeZone('UTC')), new DayResolver(Compiler::compile(new CalendarData)));

    for ($day = 0; $day < 600; $day++) {
        $timeline->plan($day);
    }

    $timeline->plan(599);

    expect($timeline->memoSize())->toBe(PeriodTimeline::MEMO_SIZE);
});

it('scans long one-off exceptions that are not expanded', function (): void {
    $hours = hours(['exceptions' => [
        ['from' => '2026-01-01', 'until' => '2026-06-30', 'ranges' => ['09:00-10:00'], 'label' => 'H1'],
        ['from' => '2026-03-01', 'until' => '2026-04-30', 'ranges' => ['10:00-11:00'], 'label' => 'Spring'],
        ['from' => '2026-09-01', 'until' => '2026-12-31', 'label' => 'H2'],
    ]]);

    expect($hours->forDate('2026-02-01')->label)->toBe('H1')
        ->and($hours->forDate('2026-03-15')->label)->toBe('Spring')
        ->and($hours->forDate('2026-08-01')->label)->toBeNull()
        ->and($hours->forDate('2026-10-01')->label)->toBe('H2');
});
