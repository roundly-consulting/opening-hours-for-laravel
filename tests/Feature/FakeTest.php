<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\Enums\Recurrence;
use RoundlyConsulting\OpeningHours\Events\OpeningHoursDeleted;
use RoundlyConsulting\OpeningHours\Events\OpeningHoursUpdated;
use RoundlyConsulting\OpeningHours\Exceptions\CalendarNotFoundException;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOpeningHoursException;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOwnerException;
use RoundlyConsulting\OpeningHours\Exceptions\StaleOpeningHoursException;
use RoundlyConsulting\OpeningHours\Facades\OpeningHours;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\OpeningHoursManager;
use RoundlyConsulting\OpeningHours\Testing\OpeningHoursFake;
use RoundlyConsulting\OpeningHours\Testing\RecordedWrite;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\Clinic;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\PlainOwner;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;

it('swaps in a manager subtype that the container and the owner trait reach', function (): void {
    $fake = OpeningHours::fake();

    expect($fake)->toBeInstanceOf(OpeningHoursManager::class)
        ->and(app(OpeningHoursManager::class))->toBe($fake)
        ->and(OpeningHours::getFacadeRoot())->toBe($fake);
});

it('records writes without touching the database or firing events', function (): void {
    $clinic = Clinic::query()->create(['timezone' => 'Europe/Bratislava']);
    OpeningHours::sync($clinic, ['week' => ['monday' => ['09:00-10:00']], 'exceptions' => [['date' => '2026-01-06']]]);
    OpeningHours::sync($clinic, [], 'pickup');
    $ruleId = OpeningHours::exceptions($clinic)->all()[0]->id;
    $revision = OpeningHours::calendar($clinic)->revision;
    Event::fake([OpeningHoursUpdated::class, OpeningHoursDeleted::class]);
    $fake = OpeningHours::fake();

    $hours = OpeningHours::sync($clinic, ['week' => ['tuesday' => ['09:00-10:00']]]);
    $rule = OpeningHours::exceptions($clinic)->open('2026-12-31', ['09:00-13:00'], label: 'NYE');
    $yearly = OpeningHours::exceptions($clinic, 'pickup')->closed('12-25');

    expect($hours->isOpenAt(at('2026-09-29 09:30')))->toBeTrue()
        ->and($hours->timezone()->getName())->toBe('Europe/Bratislava')
        ->and($rule->exists)->toBeFalse()
        ->and($rule->label)->toBe('NYE')
        ->and($rule->starts_on)->toEqual(ld('2026-12-31'))
        ->and($yearly->recurrence)->toBe(Recurrence::Yearly)
        ->and(OpeningHours::exceptions($clinic)->remove($ruleId))->toBeTrue()
        ->and(OpeningHours::delete($clinic, force: true))->toBeTrue()
        ->and(Calendar::query()->count())->toBe(2)
        ->and(OpeningHours::calendar($clinic)->revision)->toBe($revision)
        ->and(ExceptionRule::query()->count())->toBe(1)
        ->and($fake->writes())->toHaveCount(5)
        ->and($fake->writes()[0])->toBeInstanceOf(RecordedWrite::class);

    OpeningHours::refresh($clinic);

    expect(OpeningHours::calendar($clinic)->revision)->toBe($revision);
    Event::assertNothingDispatched();
});

it('still validates what it records', function (): void {
    OpeningHours::fake();
    $clinic = Clinic::query()->create();

    expect(fn () => OpeningHours::sync($clinic, ['week' => ['monday' => ['10:00-09:00', '09:30-11:00']]]))
        ->toThrow(InvalidOpeningHoursException::class)
        ->and(fn () => OpeningHours::sync($clinic, new CalendarData(exceptions: [ExceptionData::make('2026-12-24'), ExceptionData::make('2026-12-24')])))
        ->toThrow(InvalidOpeningHoursException::class);

    OpeningHours::assertNothingSynced();
});

it('refuses every write the real manager refuses', function (): void {
    $clinic = Clinic::query()->create();
    OpeningHours::sync($clinic, ['week' => ['monday' => ['09:00-17:00']], 'exceptions' => [['date' => '2026-12-24']]]);
    $bare = Clinic::query()->create();
    $revision = OpeningHours::calendar($clinic)->revision;
    $fake = OpeningHours::fake();

    expect(fn () => OpeningHours::exceptions($clinic)->open('2026-12-31', ['09:00-13:00', '10:00-11:00']))->toThrow(InvalidOpeningHoursException::class)
        ->and(fn () => OpeningHours::exceptions($clinic)->closed('2026-12-24'))->toThrow(InvalidOpeningHoursException::class)
        ->and(fn () => OpeningHours::exceptions($clinic)->closed('2026-12-25', label: str_repeat('x', 300)))->toThrow(InvalidOpeningHoursException::class)
        ->and(fn () => OpeningHours::exceptions($bare)->closed('2026-12-25'))->toThrow(CalendarNotFoundException::class)
        ->and(fn () => OpeningHours::exceptions($bare)->remove(1))->toThrow(CalendarNotFoundException::class)
        ->and(fn () => OpeningHours::sync($clinic, [], 'Bad Key!'))->toThrow(InvalidOpeningHoursException::class)
        ->and(fn () => OpeningHours::sync(new Clinic, []))->toThrow(InvalidOwnerException::class)
        ->and(fn () => OpeningHours::sync($clinic, [], expectedRevision: $revision + 1))->toThrow(StaleOpeningHoursException::class)
        ->and(fn () => OpeningHours::sync($clinic, [], expectedRevision: 0))->toThrow(StaleOpeningHoursException::class)
        ->and(fn () => OpeningHours::sync($bare, ['exceptions' => [['id' => 1, 'date' => '2026-12-24']]]))->toThrow(InvalidOpeningHoursException::class)
        ->and(fn () => OpeningHours::edit($clinic)->expectRevision($revision + 1)->save())->toThrow(StaleOpeningHoursException::class);

    $fake->assertNothingWritten();
});

it('enforces the calendars-per-owner limit like the real manager', function (): void {
    config()->set('opening-hours.limits.calendars', 1);
    $clinic = Clinic::query()->create();
    OpeningHours::sync($clinic, []);
    OpeningHours::fake();

    expect(fn () => OpeningHours::sync($clinic, [], 'pickup'))->toThrow(InvalidOpeningHoursException::class);

    OpeningHours::sync($clinic, [], expectedRevision: OpeningHours::calendar($clinic)->revision);
    OpeningHours::assertSynced($clinic);
});

it('returns false and records nothing where the real manager has nothing to do', function (): void {
    $clinic = Clinic::query()->create();
    OpeningHours::sync($clinic, ['exceptions' => [['date' => '2026-12-24']]]);
    $ruleId = OpeningHours::exceptions($clinic)->all()[0]->id;
    $foreign = Clinic::query()->create();
    OpeningHours::sync($foreign, ['exceptions' => [['date' => '2026-12-25']]]);
    $foreignRule = OpeningHours::exceptions($foreign)->all()[0]->id;
    $bare = Clinic::query()->create();
    $fake = OpeningHours::fake();

    expect(OpeningHours::exceptions($clinic)->remove(999))->toBeFalse()
        ->and(OpeningHours::exceptions($clinic)->remove($foreignRule))->toBeFalse()
        ->and(OpeningHours::delete($bare))->toBeFalse()
        ->and(OpeningHours::delete(new Clinic))->toBeFalse()
        ->and(OpeningHours::delete($clinic, 'pickup', force: true))->toBeFalse();

    OpeningHours::refresh($bare);
    $fake->assertNothingWritten();

    expect(OpeningHours::exceptions($clinic)->remove($ruleId))->toBeTrue()
        ->and(OpeningHours::delete($clinic))->toBeTrue();
    OpeningHours::refresh($clinic);

    $fake->assertExceptionRemoved($clinic, $ruleId);
    $fake->assertDeleted($clinic, force: false);
    $fake->assertRefreshed($clinic);
});

it('deletes a soft-deleted calendar only when forced, like the real manager', function (): void {
    $clinic = Clinic::query()->create();
    OpeningHours::sync($clinic, []);
    OpeningHours::delete($clinic);
    $fake = OpeningHours::fake();

    expect(OpeningHours::delete($clinic))->toBeFalse()
        ->and(OpeningHours::delete($clinic, force: true))->toBeTrue();
    $fake->assertDeleted($clinic, force: true);
});

it('keeps reads on the database', function (): void {
    $clinic = Clinic::query()->create();
    OpeningHours::sync($clinic, ['exceptions' => [['date' => '2026-12-24', 'label' => 'Eve']]]);
    OpeningHours::fake();

    expect(OpeningHours::has($clinic))->toBeTrue()
        ->and(OpeningHours::exceptions($clinic)->all()[0]->label)->toBe('Eve');
});

describe('assertions', function (): void {
    beforeEach(function (): void {
        $this->clinic = Clinic::query()->create();
        $this->other = Clinic::query()->create();
        OpeningHours::sync($this->clinic, ['exceptions' => [['date' => '2026-01-06']]]);
        OpeningHours::sync($this->clinic, [], 'pickup');
        OpeningHours::sync($this->other, []);
        $this->fake = OpeningHours::fake();
    });

    it('asserts syncs, including the owner trait and the builder', function (): void {
        $this->fake->assertNothingSynced();
        expect(fn () => $this->fake->assertSynced($this->clinic))->toThrow(AssertionFailedError::class);

        $this->clinic->setOpeningHours(['week' => ['monday' => ['09:00-10:00']]]);
        $this->clinic->editOpeningHours('pickup')->label('Pickup')->save();
        OpeningHours::edit($this->other)->closed('2026-12-24')->save();

        $this->fake->assertSynced($this->clinic);
        $this->fake->assertSynced($this->clinic, 'pickup', fn (CalendarData $data): bool => $data->label === 'Pickup');
        $this->fake->assertSynced($this->other, callback: fn (CalendarData $data): bool => count($data->exceptions) === 1);

        expect(fn () => $this->fake->assertSynced($this->clinic, 'reception'))->toThrow(AssertionFailedError::class, 'reception')
            ->and(fn () => $this->fake->assertSynced($this->clinic, 'pickup', fn (CalendarData $data): bool => false))->toThrow(AssertionFailedError::class)
            ->and(fn () => $this->fake->assertNothingSynced())->toThrow(AssertionFailedError::class, '3 were recorded');
    });

    it('asserts deletes, including the owner delete cascade', function (): void {
        $owner = PlainOwner::query()->create();
        OpeningHours::assertNothingDeleted();
        Calendar::factory()->create(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id, 'key' => 'default']);

        $owner->delete();
        OpeningHours::delete($this->clinic, 'pickup');

        $this->fake->assertDeleted($owner, force: true);
        $this->fake->assertDeleted($this->clinic, 'pickup', force: false);
        $this->fake->assertDeleted($this->clinic, 'pickup');

        expect(Calendar::query()->count())->toBe(4)
            ->and(fn () => $this->fake->assertDeleted($this->clinic, 'pickup', force: true))->toThrow(AssertionFailedError::class)
            ->and(fn () => $this->fake->assertDeleted($this->other))->toThrow(AssertionFailedError::class)
            ->and(fn () => $this->fake->assertNothingDeleted())->toThrow(AssertionFailedError::class);
    });

    it('asserts refreshes', function (): void {
        $this->fake->assertNothingRefreshed();

        OpeningHours::refresh($this->clinic);

        $this->fake->assertRefreshed($this->clinic);

        expect(fn () => $this->fake->assertRefreshed($this->other))->toThrow(AssertionFailedError::class)
            ->and(fn () => $this->fake->assertNothingRefreshed())->toThrow(AssertionFailedError::class);
    });

    it('asserts added exceptions', function (): void {
        $this->fake->assertNoExceptionsAdded();

        OpeningHours::exceptions($this->clinic)->closed('2026-12-24', label: 'Christmas Eve');

        $this->fake->assertExceptionAdded($this->clinic);
        $this->fake->assertExceptionAdded($this->clinic, callback: fn (ExceptionData $data): bool => $data->label === 'Christmas Eve'
            && $data->window->toArray()['from'] === '2026-12-24');

        expect(fn () => $this->fake->assertExceptionAdded($this->clinic, 'pickup'))->toThrow(AssertionFailedError::class)
            ->and(fn () => $this->fake->assertExceptionAdded($this->clinic, callback: fn (ExceptionData $data): bool => $data->isClosed() === false))->toThrow(AssertionFailedError::class)
            ->and(fn () => $this->fake->assertNoExceptionsAdded())->toThrow(AssertionFailedError::class);
    });

    it('asserts removed exceptions', function (): void {
        $this->fake->assertNoExceptionsRemoved();

        $ruleId = OpeningHours::exceptions($this->clinic)->all()[0]->id;
        OpeningHours::exceptions($this->clinic)->remove($ruleId);

        $this->fake->assertExceptionRemoved($this->clinic);
        $this->fake->assertExceptionRemoved($this->clinic, $ruleId);

        expect(fn () => $this->fake->assertExceptionRemoved($this->clinic, $ruleId + 1))->toThrow(AssertionFailedError::class)
            ->and(fn () => $this->fake->assertExceptionRemoved($this->other))->toThrow(AssertionFailedError::class)
            ->and(fn () => $this->fake->assertNoExceptionsRemoved())->toThrow(AssertionFailedError::class);
    });

    it('asserts that nothing was written at all', function (): void {
        $this->fake->assertNothingWritten();

        OpeningHours::refresh($this->clinic);

        expect(fn () => $this->fake->assertNothingWritten())->toThrow(AssertionFailedError::class, '1 were recorded');
    });
});

it('builds a closed exception from strings or value objects', function (): void {
    $clinic = Clinic::query()->create();
    OpeningHours::sync($clinic, []);
    $fake = OpeningHours::fake();

    OpeningHours::exceptions($clinic)->closed(LocalDate::fromString('2026-08-03'), LocalDate::fromString('2026-08-07'), yearly: true);

    $fake->assertExceptionAdded($clinic, callback: fn (ExceptionData $data): bool => $data->recurrence() === Recurrence::Yearly);
    expect($fake)->toBeInstanceOf(OpeningHoursFake::class);
});
