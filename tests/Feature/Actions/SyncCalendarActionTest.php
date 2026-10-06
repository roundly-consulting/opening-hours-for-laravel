<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\OpeningHours\Actions\SyncCalendarAction;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\Enums\ViolationCode;
use RoundlyConsulting\OpeningHours\Events\OpeningHoursUpdated;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOpeningHoursException;
use RoundlyConsulting\OpeningHours\Exceptions\StaleOpeningHoursException;
use RoundlyConsulting\OpeningHours\Facades\OpeningHours;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\Models\Schedule;
use RoundlyConsulting\OpeningHours\Models\ScheduleRange;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\Clinic;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;

function syncClinic(Clinic $clinic, array $payload, ?int $expected = null, string $key = 'default'): Calendar
{
    return app(SyncCalendarAction::class)->execute($clinic, CalendarData::fromArray($payload), $key, $expected);
}

it('creates, updates by id and soft-deletes what is gone', function (): void {
    $clinic = Clinic::query()->create();
    $calendar = syncClinic($clinic, [
        'schedules' => [['week' => ['monday' => ['09:00-10:00']]], ['window' => ['from' => '07-01', 'until' => '08-31'], 'priority' => 5, 'week' => []]],
        'exceptions' => [['date' => '2026-12-24'], ['date' => '12-25']],
    ]);

    $base = Schedule::query()->where('priority', 0)->firstOrFail();
    $christmas = ExceptionRule::query()->where('recurrence', 'yearly')->firstOrFail();

    $calendar = syncClinic($clinic, [
        'schedules' => [['id' => $base->id, 'label' => 'Regular', 'week' => ['monday' => ['10:00-11:00'], 'tuesday' => ['10:00-11:00']]]],
        'exceptions' => [['id' => $christmas->id, 'date' => '12-25', 'label' => 'Christmas']],
    ]);

    expect($calendar->revision)->toBe(2)
        ->and(Schedule::query()->count())->toBe(1)
        ->and(Schedule::query()->onlyTrashed()->count())->toBe(1)
        ->and(Schedule::query()->firstOrFail()->id)->toBe($base->id)
        ->and(Schedule::query()->firstOrFail()->label)->toBe('Regular')
        ->and(ScheduleRange::query()->count())->toBe(2)
        ->and(ExceptionRule::query()->count())->toBe(1)
        ->and(ExceptionRule::query()->firstOrFail()->label)->toBe('Christmas')
        ->and($calendar->toData()->exceptions[0]->window->toArray())->toBe(['from' => '12-25', 'until' => '12-25', 'recurrence' => 'yearly']);
});

it('restores a trashed row when its id comes back', function (): void {
    $clinic = Clinic::query()->create();
    syncClinic($clinic, ['exceptions' => [['date' => '2026-12-24']]]);
    $rule = ExceptionRule::query()->firstOrFail();
    syncClinic($clinic, ['exceptions' => []]);

    syncClinic($clinic, ['exceptions' => [['id' => $rule->id, 'date' => '2026-12-31']]]);

    expect(ExceptionRule::query()->count())->toBe(1)
        ->and(ExceptionRule::query()->firstOrFail()->id)->toBe($rule->id)
        ->and(ExceptionRule::query()->firstOrFail()->starts_on->toDateString())->toBe('2026-12-31');
});

it('rejects ids of another calendar (IDOR)', function (): void {
    $other = Clinic::query()->create();
    syncClinic($other, ['schedules' => [['week' => []]], 'exceptions' => [['date' => '2026-12-24']]]);
    $foreignSchedule = Schedule::query()->firstOrFail()->id;
    $foreignRule = ExceptionRule::query()->firstOrFail()->id;
    $clinic = Clinic::query()->create();

    try {
        syncClinic($clinic, ['schedules' => [['id' => $foreignSchedule, 'week' => []]]]);
        $this->fail('Expected UnknownId.');
    } catch (InvalidOpeningHoursException $exception) {
        expect($exception->violations()->first()?->code)->toBe(ViolationCode::UnknownId)
            ->and($exception->violations()->first()?->path)->toBe('schedules.0.id');
    }

    expect(fn () => syncClinic($clinic, ['exceptions' => [['id' => $foreignRule, 'date' => '2026-01-01']]]))
        ->toThrow(InvalidOpeningHoursException::class)
        ->and(Schedule::query()->where('calendar_id', '!=', Schedule::query()->firstOrFail()->calendar_id)->count())->toBe(0);
});

it('rejects an id used twice instead of silently dropping one of the rows', function (): void {
    $clinic = Clinic::query()->create();
    syncClinic($clinic, ['exceptions' => [['date' => '2026-12-24'], ['date' => '2026-12-31']]]);
    $payload = OpeningHours::calendar($clinic)?->toData()->toArray() ?? [];
    // A client duplicated a row (copy-paste in an editor) and kept its id.
    $payload['exceptions'][1]['id'] = $payload['exceptions'][0]['id'];

    try {
        syncClinic($clinic, $payload);
        $this->fail('Expected DuplicateId.');
    } catch (InvalidOpeningHoursException $exception) {
        expect($exception->violations()->first()?->code)->toBe(ViolationCode::DuplicateId)
            ->and($exception->violations()->first()?->path)->toBe('exceptions.1.id');
    }

    expect(ExceptionRule::query()->pluck('starts_on')->map(fn ($date) => (string) $date)->sort()->values()->all())->toBe(['2026-12-24', '2026-12-31']);
});

it('checks the expected revision and rolls back completely on a mismatch', function (): void {
    $clinic = Clinic::query()->create();
    syncClinic($clinic, ['week' => ['monday' => ['09:00-10:00']]], expected: 0);

    expect(fn () => syncClinic($clinic, ['week' => []], expected: 0))->toThrow(StaleOpeningHoursException::class)
        ->and(fn () => syncClinic($clinic, ['week' => []], expected: 7))->toThrow(StaleOpeningHoursException::class);

    try {
        syncClinic($clinic, ['week' => []], expected: 7);
    } catch (StaleOpeningHoursException $exception) {
        expect($exception->expected)->toBe(7)->and($exception->actual)->toBe(1);
    }

    expect(ScheduleRange::query()->count())->toBe(1)
        ->and(Calendar::query()->firstOrFail()->revision)->toBe(1)
        ->and(syncClinic($clinic, ['week' => []], expected: 1)->revision)->toBe(2)
        ->and(fn () => syncClinic(Clinic::query()->create(), ['week' => []], expected: 3))->toThrow(StaleOpeningHoursException::class);
});

it('bumps the revision once and fires one event per sync', function (): void {
    Event::fake([OpeningHoursUpdated::class]);
    $clinic = Clinic::query()->create();

    $calendar = syncClinic($clinic, [
        'week' => ['monday' => ['09:00-10:00', '11:00-12:00'], 'tuesday' => ['09:00-10:00']],
        'exceptions' => [['date' => '2026-12-24', 'ranges' => ['10:00-11:00']]],
    ]);

    expect($calendar->revision)->toBe(1);
    Event::assertDispatchedTimes(OpeningHoursUpdated::class, 1);
    Event::assertDispatched(fn (OpeningHoursUpdated $event): bool => $event->calendarId === $calendar->id
        && $event->ownerType === $clinic->getMorphClass()
        && (string) $event->ownerId === (string) $clinic->id
        && $event->calendarKey === 'default'
        && $event->revision === 1);
});

it('restores a soft-deleted calendar on the next sync, keeping its revision history', function (): void {
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours(['week' => ['monday' => ['09:00-10:00']]]);
    expect(OpeningHours::delete($clinic))->toBeTrue()
        ->and($clinic->hasOpeningHours())->toBeFalse()
        ->and($clinic->openingHours()->isAlwaysClosed())->toBeTrue()
        ->and(OpeningHours::delete($clinic))->toBeFalse();

    $calendar = syncClinic($clinic, ['week' => ['monday' => ['09:00-10:00']]], expected: 0);

    expect($calendar->trashed())->toBeFalse()
        ->and($calendar->revision)->toBe(2)
        ->and(Calendar::query()->withTrashed()->count())->toBe(1);
});

it('survives a simulated concurrent first insert (createOrFirst)', function (): void {
    $clinic = Clinic::query()->create();
    Calendar::query()->create(['owner_type' => $clinic->getMorphClass(), 'owner_id' => $clinic->id, 'key' => 'default', 'revision' => 0]);

    $calendar = syncClinic($clinic, ['week' => ['monday' => ['09:00-10:00']]]);

    expect(Calendar::query()->count())->toBe(1)->and($calendar->revision)->toBe(1);
});

it('refuses a date it could not read back, persisting nothing', function (): void {
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours(['week' => ['monday' => ['09:00-17:00']]]);
    $far = LocalDate::fromString('2026-10-06')->addDays(80000);

    try {
        $clinic->editOpeningHours()->closed($far)->save();
        $this->fail('An out-of-range date was saved.');
    } catch (InvalidOpeningHoursException $exception) {
        expect($exception->violations()->first()?->code)->toBe(ViolationCode::InvalidDate)
            ->and($exception->violations()->first()?->path)->toBe('exceptions.0.from');
    }

    expect(ExceptionRule::query()->count())->toBe(0)
        ->and($clinic->openingHoursCalendar()?->revision)->toBe(1)
        ->and($clinic->editOpeningHours()->toData()->exceptions)->toBe([])
        ->and(fn () => OpeningHours::make(['schedules' => [['window' => ['from' => '1899-12-31'], 'week' => []]]]))->toThrow(InvalidOpeningHoursException::class);
});
