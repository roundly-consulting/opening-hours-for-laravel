<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\Dynamic\EasterOffsetProvider;
use RoundlyConsulting\OpeningHours\Enums\DaySource;
use RoundlyConsulting\OpeningHours\Enums\ViolationCode;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOpeningHoursException;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOwnerException;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\Schedule;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\Clinic;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\PlainOwner;

afterEach(fn () => CarbonImmutable::setTestNow());

it('stores and reads opening hours for an owner', function (): void {
    $clinic = Clinic::query()->create(['name' => 'Vet']);

    $hours = $clinic->setOpeningHours([
        'timezone' => 'Europe/Bratislava',
        'week' => ['monday' => ['08:00-12:00', '13:00-17:00'], 'friday' => ['08:00-15:00']],
        'exceptions' => [['date' => '12-25', 'label' => 'Christmas']],
    ]);

    expect($hours->revision())->toBe(1)
        ->and($hours->isOpenAt(at('2026-09-28 10:00')))->toBeTrue()
        ->and($clinic->hasOpeningHours())->toBeTrue()
        ->and($clinic->openingHours()->forDate('2026-12-25')->label)->toBe('Christmas')
        ->and($clinic->openingHoursCalendar()?->key)->toBe('default')
        ->and($clinic->openingHoursCalendars())->toBeInstanceOf(MorphMany::class)
        ->and($clinic->openingHoursCalendars()->count())->toBe(1)
        ->and(Calendar::query()->first()?->owner?->is($clinic))->toBeTrue();
});

it('keeps named calendars apart', function (): void {
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours(['week' => ['monday' => ['08:00-12:00']]]);
    $clinic->setOpeningHours(['week' => ['monday' => ['14:00-16:00']]], 'pickup');

    expect($clinic->openingHours('pickup')->forDate('2026-09-28')->toString(timeSeparator: '-'))->toBe('14:00-16:00')
        ->and($clinic->openingHours()->forDate('2026-09-28')->toString(timeSeparator: '-'))->toBe('08:00-12:00')
        ->and($clinic->hasOpeningHours('reception'))->toBeFalse()
        ->and($clinic->openingHours('reception')->isAlwaysClosed())->toBeTrue();
});

it('is a method, not a relation property', function (): void {
    $clinic = Clinic::query()->create();

    expect(fn () => $clinic->openingHours)->toThrow(LogicException::class);
});

it('reads empty and refuses writes for an unsaved owner', function (): void {
    $clinic = new Clinic(['timezone' => 'Asia/Tokyo']);

    expect($clinic->openingHours()->isAlwaysClosed())->toBeTrue()
        ->and($clinic->openingHours()->timezone()->getName())->toBe('Asia/Tokyo')
        ->and($clinic->hasOpeningHours())->toBeFalse()
        ->and(fn () => $clinic->setOpeningHours([]))->toThrow(InvalidOwnerException::class);
});

it('resolves the timezone: calendar, owner hook, config, app', function (): void {
    config()->set('app.timezone', 'UTC');
    $clinic = Clinic::query()->create(['timezone' => 'America/New_York']);
    $clinic->setOpeningHours(['week' => ['monday' => ['09:00-10:00']]]);

    expect($clinic->openingHours()->timezone()->getName())->toBe('America/New_York');

    $clinic->setOpeningHours(['timezone' => 'Europe/Bratislava', 'week' => []]);
    expect($clinic->openingHours()->timezone()->getName())->toBe('Europe/Bratislava');

    $plain = PlainOwner::query()->create();
    $plain->setOpeningHours(['week' => []]);
    config()->set('opening-hours.timezone', 'Asia/Kathmandu');
    expect($plain->openingHours()->timezone()->getName())->toBe('Asia/Kathmandu');

    config()->set('opening-hours.timezone', null);
    expect($plain->openingHours()->timezone()->getName())->toBe('UTC');
});

it('applies the owner\'s dynamic exception providers', function (): void {
    Clinic::$providers = [new EasterOffsetProvider(1, 'Easter Monday')];
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours(['week' => ['monday' => ['09:00-10:00']]]);

    expect($clinic->openingHours()->forDate('2026-04-06')->source)->toBe(DaySource::Dynamic)
        ->and($clinic->openingHours()->forDate('2026-04-06')->label)->toBe('Easter Monday')
        ->and($clinic->openingHours('missing')->forDate('2026-04-06')->source)->toBe(DaySource::Dynamic);
});

it('never serves a stale loaded relation after a write', function (): void {
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours(['week' => ['monday' => ['09:00-10:00']]]);
    $clinic->load('openingHoursCalendars');

    $clinic->setOpeningHours(['week' => ['monday' => ['11:00-12:00']]]);

    expect($clinic->relationLoaded('openingHoursCalendars'))->toBeFalse()
        ->and($clinic->openingHours()->revision())->toBe(2)
        ->and($clinic->openingHours()->forDate('2026-09-28')->toString(timeSeparator: '-'))->toBe('11:00-12:00');
});

it('treats a loaded relation as authoritative', function (): void {
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours(['week' => ['monday' => ['09:00-10:00']]]);
    $loaded = Clinic::query()->withOpeningHours()->findOrFail($clinic->id);

    DB::enableQueryLog();
    DB::flushQueryLog();

    expect($loaded->openingHoursCalendar()?->key)->toBe('default')
        ->and($loaded->openingHoursCalendar('pickup'))->toBeNull()
        ->and(DB::getQueryLog())->toBe([]);
});

it('eager-loads full definitions for cache-less hosts', function (): void {
    config()->set('opening-hours.cache.enabled', false);
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours(['week' => ['monday' => ['09:00-10:00']], 'exceptions' => [['date' => '2026-12-24', 'ranges' => ['09:00-10:00']]]]);

    $loaded = Clinic::query()->withOpeningHours(definitions: true)->findOrFail($clinic->id);

    DB::enableQueryLog();
    DB::flushQueryLog();

    expect($loaded->openingHours()->isOpenAt(at('2026-09-28 09:30', 'UTC')))->toBeTrue()
        ->and(DB::getQueryLog())->toBe([]);
});

it('edits the current definition optimistically', function (): void {
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours(['week' => ['monday' => ['09:00-10:00']]]);

    $hours = $clinic->editOpeningHours()->closed('2026-12-24', label: 'Eve')->save();

    expect($hours->revision())->toBe(2)
        ->and($hours->definition()->schedules)->toHaveCount(1)
        ->and(Schedule::query()->withTrashed()->count())->toBe(1)
        ->and($hours->forDate('2026-12-24')->label)->toBe('Eve');
});

it('validates the calendar key and the calendar limit', function (): void {
    config()->set('opening-hours.limits.calendars', 2);
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours([], 'a');
    $clinic->setOpeningHours([], 'b');
    $clinic->setOpeningHours(['week' => ['monday' => ['09:00-10:00']]], 'a');

    try {
        $clinic->setOpeningHours([], 'c');
        $this->fail('Expected the calendar limit to apply.');
    } catch (InvalidOpeningHoursException $exception) {
        expect($exception->violations()->first()?->code)->toBe(ViolationCode::LimitExceeded);
    }

    expect(fn () => $clinic->setOpeningHours([], 'Not Valid'))->toThrow(InvalidOpeningHoursException::class);
});

it('deletes calendars with a permanently deleted owner only', function (): void {
    $plain = PlainOwner::query()->create();
    $plain->setOpeningHours(['week' => []]);
    $soft = Clinic::query()->create();
    $soft->setOpeningHours(['week' => []]);
    $forced = Clinic::query()->create();
    $forced->setOpeningHours(['week' => []]);

    $plain->delete();
    $soft->delete();
    $forced->forceDelete();

    expect(Calendar::query()->withTrashed()->where('owner_id', $plain->id)->where('owner_type', $plain->getMorphClass())->exists())->toBeFalse()
        ->and(Calendar::query()->where('owner_id', $soft->id)->where('owner_type', $soft->getMorphClass())->exists())->toBeTrue()
        ->and(Calendar::query()->withTrashed()->where('owner_id', $forced->id)->where('owner_type', $forced->getMorphClass())->exists())->toBeFalse();
});

it('keeps calendars when delete_with_owner is off', function (): void {
    config()->set('opening-hours.delete_with_owner', false);
    $plain = PlainOwner::query()->create();
    $plain->setOpeningHours(['week' => []]);
    $plain->delete();

    expect(Calendar::query()->count())->toBe(1);
});

it('accepts a DTO and the canonical array alike', function (): void {
    $clinic = Clinic::query()->create();
    $hours = $clinic->setOpeningHours(CalendarData::fromArray(['week' => ['tuesday' => ['09:00-10:00']]]));

    expect($hours->forDate('2026-09-29')->isOpen())->toBeTrue();
});
