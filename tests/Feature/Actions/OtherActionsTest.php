<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\OpeningHours\Actions\AddExceptionAction;
use RoundlyConsulting\OpeningHours\Actions\BumpRevisionAction;
use RoundlyConsulting\OpeningHours\Actions\DeleteCalendarAction;
use RoundlyConsulting\OpeningHours\Actions\PruneAction;
use RoundlyConsulting\OpeningHours\Actions\RemoveExceptionAction;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\Events\OpeningHoursDeleted;
use RoundlyConsulting\OpeningHours\Events\OpeningHoursUpdated;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidDateException;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOpeningHoursException;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidTimeException;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\ExceptionRange;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\Models\Interval;
use RoundlyConsulting\OpeningHours\Models\Schedule;
use RoundlyConsulting\OpeningHours\Models\ScheduleRange;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\Clinic;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;

afterEach(fn () => CarbonImmutable::setTestNow());

function clinicCalendar(array $payload = ['week' => ['monday' => ['09:00-17:00']]]): Calendar
{
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours($payload);

    return $clinic->openingHoursCalendar() ?? throw new RuntimeException('no calendar');
}

it('adds an exception validated against the whole definition', function (): void {
    $calendar = clinicCalendar(['exceptions' => [['date' => '2026-12-24']]]);
    $rule = app(AddExceptionAction::class)->execute($calendar, new ExceptionData(AbsoluteWindow::single(ld('2026-12-31')), [TimeRange::fromString('09:00-12:00')], 'NYE'));

    expect($rule->label)->toBe('NYE')
        ->and($rule->ranges)->toHaveCount(1)
        ->and($calendar->fresh()?->revision)->toBe(2)
        ->and(fn () => app(AddExceptionAction::class)->execute($calendar, new ExceptionData(AbsoluteWindow::single(ld('2026-12-24')))))
        ->toThrow(InvalidOpeningHoursException::class);
});

it('removes only this calendar\'s exceptions', function (): void {
    $calendar = clinicCalendar(['exceptions' => [['date' => '2026-12-24']]]);
    $other = clinicCalendar(['exceptions' => [['date' => '2026-12-25']]]);
    $foreign = ExceptionRule::query()->where('calendar_id', $other->id)->firstOrFail();
    $own = ExceptionRule::query()->where('calendar_id', $calendar->id)->firstOrFail();

    expect(app(RemoveExceptionAction::class)->execute($calendar, $foreign->id))->toBeFalse()
        ->and(app(RemoveExceptionAction::class)->execute($calendar, $own->id))->toBeTrue()
        ->and(ExceptionRule::query()->count())->toBe(1)
        ->and($calendar->fresh()?->revision)->toBe(2);
});

it('soft or force deletes a calendar and drops its intervals', function (): void {
    Event::fake([OpeningHoursDeleted::class]);
    $calendar = clinicCalendar();
    Interval::factory()->create(['calendar_id' => $calendar->id]);

    app(DeleteCalendarAction::class)->execute($calendar);

    expect(Calendar::query()->count())->toBe(0)
        ->and(Calendar::query()->withTrashed()->count())->toBe(1)
        ->and(Interval::query()->count())->toBe(0);

    app(DeleteCalendarAction::class)->execute($calendar, force: true);

    expect(Calendar::query()->withTrashed()->count())->toBe(0)
        ->and(Schedule::query()->withTrashed()->count())->toBe(0)
        ->and(ScheduleRange::query()->count())->toBe(0);

    Event::assertDispatched(OpeningHoursDeleted::class, fn (OpeningHoursDeleted $event): bool => $event->forced);
    Event::assertDispatched(OpeningHoursDeleted::class, fn (OpeningHoursDeleted $event): bool => ! $event->forced);
});

it('bumps revisions atomically, including for direct row edits', function (): void {
    Event::fake([OpeningHoursUpdated::class]);
    $calendar = clinicCalendar();
    $schedule = Schedule::query()->firstOrFail();

    ScheduleRange::query()->create(['schedule_id' => $schedule->id, 'weekday' => Weekday::Tuesday, 'start_minute' => 60, 'end_minute' => 120]);
    expect($calendar->fresh()?->revision)->toBe(2);

    $schedule->update(['label' => 'Edited']);
    expect($calendar->fresh()?->revision)->toBe(3);

    $rule = ExceptionRule::query()->create(['calendar_id' => $calendar->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-01']);
    ExceptionRange::query()->create(['exception_rule_id' => $rule->id, 'start_minute' => 60, 'end_minute' => 90]);
    expect($calendar->fresh()?->revision)->toBe(5)
        ->and(app(BumpRevisionAction::class)->execute($calendar->id))->toBe(6)
        ->and(app(BumpRevisionAction::class)->execute(999_999))->toBe(0);
});

it('guards range and window invariants on direct writes', function (array $attributes): void {
    $schedule = clinicCalendar()->schedules()->firstOrFail();

    ScheduleRange::query()->create(['schedule_id' => $schedule->id, ...$attributes]);
})->throws(InvalidTimeException::class)->with([
    [['weekday' => 1, 'start_minute' => 1440, 'end_minute' => 60]],
    [['weekday' => 1, 'start_minute' => 60, 'end_minute' => 0]],
    [['weekday' => 1, 'start_minute' => 60, 'end_minute' => 60]],
    [['weekday' => 8, 'start_minute' => 60, 'end_minute' => 120]],
]);

it('rejects an inverted one-off exception row', function (): void {
    ExceptionRule::query()->create(['calendar_id' => clinicCalendar()->id, 'starts_on' => '2026-02-01', 'ends_on' => '2026-01-01']);
})->throws(InvalidDateException::class);

it('prunes past exceptions and purges old trashed rows', function (): void {
    CarbonImmutable::setTestNow('2026-09-26 12:00:00');
    $calendar = clinicCalendar(['exceptions' => [
        ['date' => '2026-01-01'],
        ['from' => '2026-08-01', 'until' => '2026-09-20'],
        ['date' => '12-25'],
        ['date' => '2026-12-24'],
    ]]);
    $revision = $calendar->revision;

    $dry = app(PruneAction::class)->execute(30, null, dryRun: true);
    expect($dry->exceptionsPruned)->toBe(1)->and(ExceptionRule::query()->count())->toBe(4);

    $result = app(PruneAction::class)->execute(30, null);
    expect($result->exceptionsPruned)->toBe(1)
        ->and(ExceptionRule::query()->count())->toBe(3)
        ->and($calendar->fresh()?->revision)->toBe($revision + 1);

    CarbonImmutable::setTestNow('2027-02-01 12:00:00');
    expect(app(PruneAction::class)->execute(null, 90, dryRun: true)->rowsPurged)->toBe(1)
        ->and(app(PruneAction::class)->execute(null, 90)->rowsPurged)->toBe(1)
        ->and(ExceptionRule::query()->withTrashed()->count())->toBe(3)
        ->and(app(PruneAction::class)->execute(null, null)->rowsPurged)->toBe(0);
});
