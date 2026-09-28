<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\OpeningHours\CalendarExceptions;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\Enums\DaySource;
use RoundlyConsulting\OpeningHours\Enums\Recurrence;
use RoundlyConsulting\OpeningHours\Events\OpeningHoursDeleted;
use RoundlyConsulting\OpeningHours\Events\OpeningHoursUpdated;
use RoundlyConsulting\OpeningHours\Exceptions\CalendarNotFoundException;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOpeningHoursException;
use RoundlyConsulting\OpeningHours\Exceptions\StaleOpeningHoursException;
use RoundlyConsulting\OpeningHours\Facades\OpeningHours;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\OpeningHoursManager;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\Clinic;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\PlainOwner;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;

it('documents its root, fakes for real and reaches every action', function (): void {
    expect(OpeningHours::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});

function shop(array $definition = ['week' => ['monday' => ['09:00-17:00']]], ?string $calendar = null): Clinic
{
    $shop = Clinic::query()->create(['timezone' => 'Europe/Bratislava']);
    OpeningHours::sync($shop, $definition, $calendar);

    return $shop;
}

describe('exceptions()', function (): void {
    it('adds a closed day without rewriting the rest of the definition', function (): void {
        $shop = shop(['week' => ['monday' => ['09:00-17:00']], 'exceptions' => [['date' => '12-25', 'label' => 'Christmas']]]);
        $scheduleIds = Calendar::query()->firstOrFail()->schedules()->pluck('id')->all();

        $rule = OpeningHours::exceptions($shop)->closed('2026-12-24', label: 'Christmas Eve');

        expect($rule)->toBeInstanceOf(ExceptionRule::class)
            ->and($rule->exists)->toBeTrue()
            ->and($rule->label)->toBe('Christmas Eve')
            ->and($rule->recurrence)->toBe(Recurrence::None)
            ->and(Calendar::query()->firstOrFail()->schedules()->pluck('id')->all())->toBe($scheduleIds)
            ->and(OpeningHours::for($shop)->forDate('2026-12-24')->isClosed())->toBeTrue()
            ->and(OpeningHours::for($shop)->forDate('2026-12-24')->label)->toBe('Christmas Eve')
            ->and(OpeningHours::for($shop)->revision())->toBe(2);
    });

    it('adds custom hours and yearly days', function (): void {
        $shop = shop();

        OpeningHours::exceptions($shop)->open('2026-12-31', ['09:00-13:00'], label: 'NYE');
        OpeningHours::exceptions($shop)->closed('01-01', label: 'New Year');
        OpeningHours::exceptions($shop)->open('2026-08-03', [TimeRange::fromString('10:00-12:00')], until: '2026-08-07');

        $hours = OpeningHours::for($shop);

        expect($hours->forDate('2026-12-31')->toString(timeSeparator: '-'))->toBe('09:00-13:00')
            ->and($hours->forDate('2026-12-31')->source)->toBe(DaySource::Exception)
            ->and($hours->forDate('2027-01-01')->isClosed())->toBeTrue()
            ->and($hours->forDate('2028-01-01')->label)->toBe('New Year')
            ->and($hours->forDate('2026-08-05')->toString(timeSeparator: '-'))->toBe('10:00-12:00');
    });

    it('adds a prepared ExceptionData and ignores its id', function (): void {
        $shop = shop(['exceptions' => [['date' => '2026-12-24']]]);
        $existing = OpeningHours::exceptions($shop)->all()[0];
        $copy = new ExceptionData(AbsoluteWindow::single(ld('2026-12-31')), [], 'Copy', ['k' => 'v'], $existing->id);

        $rule = OpeningHours::exceptions($shop)->add($copy);

        expect($rule->id)->not->toBe($existing->id)
            ->and($rule->meta)->toBe(['k' => 'v'])
            ->and(ExceptionRule::query()->count())->toBe(2);
    });

    it('validates the new exception against the whole definition', function (): void {
        $shop = shop(['exceptions' => [['date' => '2026-12-24']]]);

        expect(fn () => OpeningHours::exceptions($shop)->closed('2026-12-24'))->toThrow(InvalidOpeningHoursException::class)
            ->and(ExceptionRule::query()->count())->toBe(1)
            ->and(OpeningHours::for($shop)->revision())->toBe(1);
    });

    it('lists the stored exceptions with their ids', function (): void {
        $shop = shop(['exceptions' => [['date' => '2026-12-24', 'label' => 'Eve'], ['date' => '12-25']]]);

        $all = OpeningHours::exceptions($shop)->all();

        expect($all)->toHaveCount(2)
            ->and($all[0])->toBeInstanceOf(ExceptionData::class)
            ->and($all[0]->label)->toBe('Eve')
            ->and($all[0]->id)->toBe(ExceptionRule::query()->where('label', 'Eve')->value('id'))
            ->and($all[1]->recurrence())->toBe(Recurrence::Yearly)
            ->and(OpeningHours::exceptions($shop, 'missing')->all())->toBe([]);
    });

    it('removes an exception of this calendar', function (): void {
        $shop = shop(['exceptions' => [['date' => '2026-12-24']]]);
        $id = OpeningHours::exceptions($shop)->all()[0]->id;

        expect(OpeningHours::exceptions($shop)->remove((int) $id))->toBeTrue()
            ->and(OpeningHours::exceptions($shop)->all())->toBe([])
            ->and(OpeningHours::exceptions($shop)->remove((int) $id))->toBeFalse()
            ->and(OpeningHours::for($shop)->revision())->toBe(2);
    });

    it('refuses a rule of another owner or another calendar', function (): void {
        $shop = shop(['exceptions' => [['date' => '2026-12-24']]]);
        OpeningHours::sync($shop, ['exceptions' => [['date' => '2026-12-26']]], 'pickup');
        $rival = shop(['exceptions' => [['date' => '2026-12-25']]]);
        $rivalRule = (int) OpeningHours::exceptions($rival)->all()[0]->id;
        $pickupRule = (int) OpeningHours::exceptions($shop, 'pickup')->all()[0]->id;

        expect(OpeningHours::exceptions($shop)->remove($rivalRule))->toBeFalse()
            ->and(OpeningHours::exceptions($shop)->remove($pickupRule))->toBeFalse()
            ->and(ExceptionRule::query()->count())->toBe(3)
            ->and(OpeningHours::for($rival)->revision())->toBe(1)
            ->and(OpeningHours::exceptions($shop, 'pickup')->remove($pickupRule))->toBeTrue();
    });

    it('throws when the calendar does not exist or was deleted', function (Closure $write): void {
        $shop = shop();
        OpeningHours::delete($shop);

        expect(fn () => $write($shop))->toThrow(CalendarNotFoundException::class, 'no live [default]')
            ->and(fn () => $write(Clinic::query()->create()))->toThrow(CalendarNotFoundException::class)
            ->and(fn () => $write(new Clinic))->toThrow(CalendarNotFoundException::class);
    })->with([
        'add' => [fn (Clinic $shop) => OpeningHours::exceptions($shop)->closed('2026-12-24')],
        'remove' => [fn (Clinic $shop) => OpeningHours::exceptions($shop)->remove(1)],
    ]);

    it('drops a stale loaded relation after each write', function (): void {
        $shop = shop();
        $shop->load('openingHoursCalendars');
        OpeningHours::exceptions($shop)->closed('2026-12-28'); // a Monday

        expect($shop->relationLoaded('openingHoursCalendars'))->toBeFalse()
            ->and(OpeningHours::for($shop)->forDate('2026-12-28')->isClosed())->toBeTrue();

        $shop->load('openingHoursCalendars');
        OpeningHours::exceptions($shop)->remove((int) OpeningHours::exceptions($shop)->all()[0]->id);

        expect($shop->relationLoaded('openingHoursCalendars'))->toBeFalse()
            ->and(OpeningHours::for($shop)->forDate('2026-12-28')->isClosed())->toBeFalse();
    });

    it('announces each write once', function (): void {
        $shop = shop();
        Event::fake([OpeningHoursUpdated::class]);

        OpeningHours::exceptions($shop)->closed('2026-12-24');

        Event::assertDispatchedTimes(OpeningHoursUpdated::class, 1);
    });
});

describe('race safety', function (): void {
    it('a stale builder cannot clobber an exception added meanwhile', function (): void {
        $shop = shop(['week' => ['monday' => ['09:00-17:00']]]);
        $builder = OpeningHours::edit($shop)->closed('2026-12-31', label: 'Stale edit');

        OpeningHours::exceptions($shop)->closed('2026-12-24', label: 'Christmas Eve');

        expect(fn () => $builder->save())->toThrow(StaleOpeningHoursException::class)
            ->and(array_map(fn (ExceptionData $data) => $data->label, OpeningHours::exceptions($shop)->all()))->toBe(['Christmas Eve']);
    });

    it('adds an exception on top of a concurrent full save', function (): void {
        $shop = shop(['week' => ['monday' => ['09:00-17:00']]]);
        $handle = OpeningHours::exceptions($shop);

        OpeningHours::edit($shop)->closed('2026-12-31', label: 'Saved first')->save();
        $handle->closed('2026-12-24', label: 'Added second');

        expect(array_map(fn (ExceptionData $data) => $data->label, OpeningHours::exceptions($shop)->all()))->toBe(['Saved first', 'Added second'])
            ->and(OpeningHours::for($shop)->definition()->schedules)->toHaveCount(1)
            ->and(OpeningHours::for($shop)->revision())->toBe(3);
    });
});

describe('delete()', function (): void {
    it('soft-deletes by default and purges with force', function (): void {
        Event::fake([OpeningHoursDeleted::class]);
        $shop = shop();

        expect(OpeningHours::delete($shop))->toBeTrue()
            ->and(OpeningHours::has($shop))->toBeFalse()
            ->and(Calendar::query()->withTrashed()->count())->toBe(1)
            ->and(OpeningHours::delete($shop))->toBeFalse()
            ->and(OpeningHours::delete($shop, force: true))->toBeTrue()
            ->and(Calendar::query()->withTrashed()->count())->toBe(0)
            ->and(OpeningHours::delete($shop, force: true))->toBeFalse()
            ->and(OpeningHours::delete(new Clinic, force: true))->toBeFalse();

        Event::assertDispatched(OpeningHoursDeleted::class, fn (OpeningHoursDeleted $event): bool => $event->forced);
    });

    it('targets one calendar key', function (): void {
        $shop = shop();
        OpeningHours::sync($shop, ['week' => []], 'pickup');

        OpeningHours::delete($shop, 'pickup', force: true);

        expect(OpeningHours::has($shop))->toBeTrue()
            ->and(OpeningHours::has($shop, 'pickup'))->toBeFalse();
    });

    it('purges soft-deleted calendars with a permanently deleted owner', function (): void {
        $owner = PlainOwner::query()->create();
        OpeningHours::sync($owner, ['week' => []]);
        OpeningHours::sync($owner, ['week' => []], 'pickup');
        OpeningHours::delete($owner, 'pickup');

        $owner->delete();

        expect(Calendar::query()->withTrashed()->count())->toBe(0);
    });
});

it('exposes the stored definition of a header', function (): void {
    $shop = shop(['label' => 'Front desk', 'week' => ['monday' => ['09:00-10:00']]]);

    $data = OpeningHours::definitionData(OpeningHours::calendar($shop) ?? throw new RuntimeException);

    expect($data->label)->toBe('Front desk')
        ->and($data->revision)->toBe(1);
});

it('runs the same API injected, without the facade', function (): void {
    $shop = shop();
    $manager = app(OpeningHoursManager::class);

    $rule = $manager->exceptions($shop)->closed('2026-12-24');

    expect($manager)->toBe(OpeningHours::getFacadeRoot())
        ->and($manager->exceptions($shop))->toBeInstanceOf(CalendarExceptions::class)
        ->and($manager->exceptions($shop)->remove($rule->id))->toBeTrue();
});

it('reaches a dispatcher faked after the manager was resolved', function (): void {
    // Regression: the scoped manager held constructor-injected actions, so an
    // Event::fake() later in the request never saw sync()/delete() events.
    $shop = shop();
    app(OpeningHoursManager::class);
    Event::fake([OpeningHoursUpdated::class, OpeningHoursDeleted::class]);

    OpeningHours::sync($shop, ['week' => []]);
    OpeningHours::delete($shop);

    Event::assertDispatchedTimes(OpeningHoursUpdated::class, 1);
    Event::assertDispatchedTimes(OpeningHoursDeleted::class, 1);
});
