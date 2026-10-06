<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\OpeningHours\Actions\MaterializeIntervalsAction;
use RoundlyConsulting\OpeningHours\Exceptions\OutsideMaterializedHorizonException;
use RoundlyConsulting\OpeningHours\Facades\OpeningHours;
use RoundlyConsulting\OpeningHours\Jobs\MaterializeIntervalsJob;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\Interval;
use RoundlyConsulting\OpeningHours\Models\Schedule;
use RoundlyConsulting\OpeningHours\Models\ScheduleRange;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\Clinic;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\PlainOwner;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(at('2026-10-20 12:00'));
    config()->set('opening-hours.materialize.enabled', true);
    config()->set('opening-hours.materialize.days_ahead', 14);
    config()->set('opening-hours.materialize.days_behind', 1);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function materializedClinic(array $week = ['sunday' => ['00:00-24:00'], 'monday' => ['08:00-12:00', '12:00-17:00']], ?string $timezone = 'Europe/Bratislava'): Clinic
{
    $clinic = Clinic::query()->create(['timezone' => $timezone]);
    $clinic->setOpeningHours(['week' => $week]);

    return $clinic;
}

it('stores coalesced UTC intervals, DST days included', function (): void {
    Bus::fake();
    $clinic = materializedClinic();
    $count = app(MaterializeIntervalsAction::class)->execute($clinic->openingHoursCalendar(), ld('2026-10-24'), ld('2026-10-27'));
    $rows = Interval::query()->orderBy('opens_at')->get();

    expect($count)->toBe(2)
        ->and($rows->map(fn (Interval $i) => $i->opens_at.'|'.$i->closes_at)->all())->toBe([
            '2026-10-24 22:00:00|2026-10-25 23:00:00',
            '2026-10-26 07:00:00|2026-10-26 16:00:00',
        ])
        ->and($rows[0]->opensAt()->diffInHours($rows[0]->closesAt()))->toEqual(25)
        ->and($rows[0]->calendar_key)->toBe('default')
        ->and((string) $rows[0]->owner_id)->toBe((string) $clinic->id);
});

it('replaces all intervals on every run, keeping the table bounded', function (): void {
    Bus::fake();
    $clinic = materializedClinic(['monday' => ['08:00-12:00'], 'tuesday' => ['08:00-12:00'], 'wednesday' => ['08:00-12:00'], 'thursday' => ['08:00-12:00'], 'friday' => ['08:00-12:00']]);
    $job = new MaterializeIntervalsJob($clinic->openingHoursCalendar()->id);
    $counts = [];

    for ($night = 0; $night < 30; $night++) {
        CarbonImmutable::setTestNow(at('2026-10-20 03:00')->addDays($night));
        $job->handle(app(MaterializeIntervalsAction::class));
        $counts[] = Interval::query()->count();
    }

    expect(max($counts))->toBeLessThanOrEqual(12)
        ->and(Interval::query()->distinct()->count('opens_at'))->toBe(Interval::query()->count());
});

it('stitches runs clipped at internal chunk edges', function (): void {
    Bus::fake();
    config()->set('opening-hours.max_query_days', 3);
    $clinic = materializedClinic(['monday' => ['00:00-24:00'], 'tuesday' => ['00:00-24:00'], 'wednesday' => ['00:00-24:00'], 'thursday' => ['00:00-24:00'],
        'friday' => ['00:00-24:00'], 'saturday' => ['00:00-24:00'], 'sunday' => ['00:00-24:00']]);

    expect(app(MaterializeIntervalsAction::class)->execute($clinic->openingHoursCalendar(), ld('2026-10-19'), ld('2026-10-30')))->toBe(1);
});

it('filters owners open at an instant or throughout a span, in SQL', function (): void {
    Bus::fake();
    $open = materializedClinic();
    $closed = materializedClinic(['tuesday' => ['08:00-12:00']]);
    $pickup = materializedClinic(['tuesday' => ['08:00-12:00']]);
    $pickup->setOpeningHours(['week' => ['monday' => ['09:00-10:00']]], 'pickup');

    foreach (Calendar::query()->get() as $calendar) {
        app(MaterializeIntervalsAction::class)->execute($calendar, ld('2026-10-19'), ld('2026-11-03'));
    }

    $monday = at('2026-10-26 10:00');

    DB::enableQueryLog();
    $ids = Clinic::query()->whereOpenAt($monday)->pluck('id')->all();
    $bindings = DB::getQueryLog()[0]['bindings'];

    expect($ids)->toBe([$open->id])
        ->and($bindings)->toContain('2026-10-26 09:00:00')
        ->and(Clinic::query()->whereOpenAt(at('2026-10-26 09:30'), 'pickup')->pluck('id')->all())->toBe([$pickup->id])
        ->and(Clinic::query()->whereOpenThroughout(at('2026-10-26 11:00'), at('2026-10-26 13:00'))->pluck('id')->all())->toBe([$open->id])
        ->and(Clinic::query()->whereOpenThroughout(at('2026-10-26 07:00'), at('2026-10-26 13:00'))->pluck('id')->all())->toBe([])
        ->and(PlainOwner::query()->whereOpenAt($monday)->count())->toBe(0);
});

it('never matches a soft-deleted calendar', function (): void {
    Bus::fake();
    $clinic = materializedClinic();
    app(MaterializeIntervalsAction::class)->execute($clinic->openingHoursCalendar(), ld('2026-10-19'), ld('2026-11-03'));

    OpeningHours::delete($clinic);

    expect(Clinic::query()->whereOpenAt(at('2026-10-26 10:00'))->count())->toBe(0)
        ->and(app(MaterializeIntervalsAction::class)->execute(Calendar::query()->withTrashed()->firstOrFail(), ld('2026-10-19'), ld('2026-11-03')))->toBe(0);
});

it('refuses instants outside the materialized horizon', function (string $instant): void {
    Clinic::query()->whereOpenAt(at($instant))->get();
})->throws(OutsideMaterializedHorizonException::class)->with(['2026-10-18 12:00', '2026-11-04 13:00']);

it('queues a unique job per change when enabled, and none when disabled', function (): void {
    Bus::fake([MaterializeIntervalsJob::class]);
    config()->set('opening-hours.materialize.queue', 'hours');
    config()->set('opening-hours.materialize.connection', 'sync');
    $clinic = materializedClinic();

    Bus::assertDispatched(MaterializeIntervalsJob::class, fn (MaterializeIntervalsJob $job): bool => $job->uniqueId() === (string) $clinic->openingHoursCalendar()->id
        && $job->queue === 'hours' && $job->connection === 'sync');

    config()->set('opening-hours.materialize.enabled', false);
    Bus::fake([MaterializeIntervalsJob::class]);
    $clinic->setOpeningHours(['week' => []]);
    Bus::assertNotDispatched(MaterializeIntervalsJob::class);
});

it('handles a job for a calendar that no longer exists', function (): void {
    (new MaterializeIntervalsJob(999_999))->handle(app(MaterializeIntervalsAction::class));

    expect(Interval::query()->count())->toBe(0);
});

it('materializes a calendar whose owner is not an opening-hours owner', function (): void {
    Bus::fake();
    $calendar = Calendar::factory()->create(['owner_type' => 'ghost', 'owner_id' => 5, 'timezone' => 'UTC']);
    Schedule::factory()->create(['calendar_id' => $calendar->id]);
    ScheduleRange::factory()->create([
        'schedule_id' => Schedule::query()->firstOrFail()->id,
    ]);

    expect(app(MaterializeIntervalsAction::class)->execute($calendar->fresh(), ld('2026-10-19'), ld('2026-10-27')))->toBe(2);
});

it('re-materializes when the calendar changes while the job runs', function (): void {
    // The unique lock is held for the whole run, so the change's own job is dropped
    // (Bus::fake stands in for that): the running job must notice and repeat.
    Bus::fake();
    $clinic = materializedClinic(['monday' => ['08:00-12:00']]);
    $changed = false;

    DB::listen(function ($query) use (&$changed, $clinic): void {
        if (! $changed && str_contains($query->sql, 'opening_hours_intervals') && str_starts_with(strtolower($query->sql), 'insert')) {
            $changed = true;
            $clinic->setOpeningHours(['week' => ['monday' => ['14:00-16:00']]]);
        }
    });

    (new MaterializeIntervalsJob($clinic->openingHoursCalendar()->id))->handle(app(MaterializeIntervalsAction::class));

    expect($changed)->toBeTrue()
        ->and(Interval::query()->get()->map(fn (Interval $i) => $i->opensAt()->setTimezone('Europe/Bratislava')->format('H:i'))->unique()->values()->all())
        ->toBe(['14:00']);
});

it('drops the intervals of a calendar soft-deleted while the job runs', function (): void {
    Bus::fake();
    $clinic = materializedClinic(['monday' => ['08:00-12:00']]);
    $deleted = false;

    DB::listen(function ($query) use (&$deleted, $clinic): void {
        // The soft delete commits just before the job's own replace transaction writes.
        if (! $deleted && str_contains($query->sql, 'opening_hours_intervals') && str_starts_with(strtolower($query->sql), 'delete')) {
            $deleted = true;
            OpeningHours::delete($clinic);
        }
    });

    (new MaterializeIntervalsJob($clinic->openingHoursCalendar()->id))->handle(app(MaterializeIntervalsAction::class));

    expect($deleted)->toBeTrue()
        ->and(Interval::query()->count())->toBe(0);
});

it('queues one unique job per calendar until it runs, from the listener and the command', function (): void {
    Queue::fake();
    $changed = materializedClinic();
    $changed->setOpeningHours(['week' => ['monday' => ['08:00-12:00']]]);

    config()->set('opening-hours.materialize.enabled', false);
    $rolled = materializedClinic();
    config()->set('opening-hours.materialize.enabled', true);
    $ids = ['--calendar' => [(string) $rolled->openingHoursCalendar()?->id]];
    $this->artisan('opening-hours:materialize', $ids)->assertSuccessful();
    $this->artisan('opening-hours:materialize', $ids)->assertSuccessful();

    $pushed = fn (Clinic $clinic): int => Queue::pushed(MaterializeIntervalsJob::class, fn (MaterializeIntervalsJob $job): bool => $job->calendarId === $clinic->openingHoursCalendar()?->id)->count();

    expect($pushed($changed))->toBe(1)
        ->and($pushed($rolled))->toBe(1);
});

it('materializes a 25-hour fall-back day under a max_query_days of 1', function (): void {
    Bus::fake();
    config()->set('opening-hours.max_query_days', 1);
    $clinic = materializedClinic();
    $count = app(MaterializeIntervalsAction::class)->execute($clinic->openingHoursCalendar(), ld('2026-10-24'), ld('2026-10-27'));

    expect($count)->toBe(2)
        ->and(Interval::query()->orderBy('opens_at')->get()->map(fn (Interval $i) => $i->opens_at.'|'.$i->closes_at)->all())->toBe([
            '2026-10-24 22:00:00|2026-10-25 23:00:00',
            '2026-10-26 07:00:00|2026-10-26 16:00:00',
        ]);
});
