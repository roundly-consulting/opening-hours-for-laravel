<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\OpeningHours\Actions\AddExceptionAction;
use RoundlyConsulting\OpeningHours\Actions\RemoveExceptionAction;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\Exceptions\StaleOpeningHoursException;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\Clinic;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Testing\Fixtures\LockRecordingGrammar;

/**
 * SQLite compiles lockForUpdate() to nothing, so the recording grammar turns it
 * into a marker comment: each write action must lock the calendar row INSIDE
 * its transaction (depth ≥ 1), or concurrent writers could interleave.
 */
function recordLocks(): void
{
    $connection = DB::connection();

    if ($connection->getDriverName() !== 'sqlite') {
        test()->markTestSkipped('The lock recorder grammar is SQLite-only.');
    }

    $connection->setQueryGrammar(new LockRecordingGrammar($connection));
    LockRecorder::flush();
    LockRecorder::listenForMarkers();
}

it('locks the calendar row inside the transaction on every write action', function (Closure $write): void {
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours(['exceptions' => [['date' => '2026-12-24']]]);
    recordLocks();

    $write($clinic);

    $locks = array_values(array_filter(LockRecorder::recorded(), fn (array $lock) => str_contains($lock['sql'], 'opening_hours_calendars')));

    expect($locks)->toHaveCount(1)
        ->and($locks[0]['marker'])->toBe('lock-for-update')
        ->and($locks[0]['transactionDepth'])->toBeGreaterThanOrEqual(1);
})->with([
    'sync' => [fn (Clinic $clinic) => $clinic->setOpeningHours(['week' => []])],
    'add exception' => [fn (Clinic $clinic) => app(AddExceptionAction::class)->execute($clinic->openingHoursCalendar(), new ExceptionData(AbsoluteWindow::single(ld('2026-12-31'))))],
    'remove exception' => [fn (Clinic $clinic) => app(RemoveExceptionAction::class)->execute($clinic->openingHoursCalendar(), ExceptionRule::query()->firstOrFail()->id)],
]);

it('refuses a lost update from a second builder of the same revision', function (): void {
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours(['week' => ['monday' => ['09:00-10:00']]]);

    $first = $clinic->editOpeningHours()->closed('2026-12-24');
    $second = $clinic->editOpeningHours()->closed('2026-12-31');

    $first->save();

    expect(fn () => $second->save())->toThrow(StaleOpeningHoursException::class)
        ->and(ExceptionRule::query()->count())->toBe(1);

    $clinic->editOpeningHours()->closed('2026-12-31')->expectRevision(1)->ignoreConcurrentChanges()->save();

    // Seeded from the current definition, so the first writer's exception survives.
    expect(ExceptionRule::query()->count())->toBe(2);
});

it('lets an explicitly echoed revision decide', function (): void {
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours(['week' => []]);

    expect(fn () => $clinic->editOpeningHours()->expectRevision(5)->save())->toThrow(StaleOpeningHoursException::class)
        ->and($clinic->editOpeningHours()->expectRevision(1)->save()->revision())->toBe(2)
        ->and(fn () => $clinic->setOpeningHours(['week' => []], expectedRevision: 1))->toThrow(StaleOpeningHoursException::class);
});
