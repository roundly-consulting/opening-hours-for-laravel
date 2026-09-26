<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\ExceptionRange;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\Models\Schedule;
use RoundlyConsulting\OpeningHours\Models\ScheduleRange;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\Clinic;

/**
 * The columns the drivers render differently — json(b) meta, `date`, small
 * integers — round-tripped on whatever engine this leg runs.
 */
it('round-trips meta, canonical yearly dates and minute columns', function (): void {
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours([
        'meta' => ['b' => 2, 'a' => ['nested' => true]],
        'schedules' => [['window' => ['from' => '12-24', 'until' => '02-29'], 'priority' => -3, 'meta' => ['s' => 1], 'week' => [
            'sunday' => [['from' => '22:00', 'to' => '02:00', 'capacity' => 7, 'label' => 'Night', 'meta' => ['k' => 'v']]],
            'tuesday' => ['00:00-24:00'],
        ]]],
        'exceptions' => [['from' => '11-01', 'until' => '11-02', 'ranges' => ['09:00-24:00']]],
    ]);

    $calendar = Calendar::query()->firstOrFail();
    $schedule = Schedule::query()->firstOrFail();
    $night = ScheduleRange::query()->where('weekday', 7)->firstOrFail();
    $allDay = ScheduleRange::query()->where('weekday', 2)->firstOrFail();
    $rule = ExceptionRule::query()->firstOrFail();

    expect($calendar->meta['b'])->toBe(2)
        ->and($calendar->meta['a'])->toBe(['nested' => true])
        ->and($schedule->effective_from?->toDateString())->toBe('2000-12-24')
        ->and($schedule->effective_until?->toDateString())->toBe('2000-02-29')
        ->and($schedule->priority)->toBe(-3)
        ->and($night->start_minute->minutes)->toBe(1320)
        ->and($night->end_minute->minutes)->toBe(120)
        ->and($night->capacity)->toBe(7)
        ->and($night->meta)->toBe(['k' => 'v'])
        ->and($allDay->end_minute->minutes)->toBe(1440)
        ->and($rule->starts_on->toDateString())->toBe('2000-11-01')
        ->and(ExceptionRange::query()->firstOrFail()->end_minute->format())->toBe('24:00')
        ->and($clinic->openingHours()->definition()->schedules[0]->window?->toArray())->toBe(['from' => '12-24', 'until' => '02-29', 'recurrence' => 'yearly']);
});

it('enforces one calendar per owner and key', function (): void {
    $clinic = Clinic::query()->create();
    $identity = ['owner_type' => $clinic->getMorphClass(), 'owner_id' => $clinic->id, 'key' => 'default'];
    Calendar::query()->create($identity);

    Calendar::query()->create($identity);
})->throws(UniqueConstraintViolationException::class);

it('cascades a forced calendar delete to every child row', function (): void {
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours(['week' => ['monday' => ['09:00-10:00']], 'exceptions' => [['date' => '2026-12-24', 'ranges' => ['09:00-10:00']]]]);

    Calendar::query()->firstOrFail()->forceDelete();

    expect(Schedule::query()->withTrashed()->count())->toBe(0)
        ->and(ScheduleRange::query()->count())->toBe(0)
        ->and(ExceptionRule::query()->withTrashed()->count())->toBe(0)
        ->and(ExceptionRange::query()->count())->toBe(0);
});
