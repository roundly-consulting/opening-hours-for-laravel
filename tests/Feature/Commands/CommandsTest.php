<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use RoundlyConsulting\OpeningHours\Jobs\MaterializeIntervalsJob;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\Models\Interval;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\Clinic;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\NotAnOwner;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Relation::morphMap([], false);
});

it('shows an owner\'s status and coming days', function (): void {
    Relation::morphMap(['clinic' => Clinic::class]);
    $clinic = Clinic::query()->create(['timezone' => 'Europe/Bratislava']);
    $clinic->setOpeningHours(['week' => ['monday' => ['08:00-12:00']], 'exceptions' => [['date' => '2026-12-24', 'label' => 'Eve']]]);

    $this->artisan('opening-hours:show', ['owner' => 'clinic', 'id' => $clinic->id, '--at' => '2026-09-28T10:00:00+02:00', '--days' => 2])
        ->expectsOutputToContain('Open until 2026-09-28 12:00')
        ->expectsTable(['Date', 'Weekday', 'Source', 'Label', 'Hours'], [
            ['2026-09-28', 'Monday', 'schedule', '', '08:00–12:00'],
            ['2026-09-29', 'Tuesday', 'schedule', '', 'Closed'],
        ])
        ->assertSuccessful();

    $this->artisan('opening-hours:show', ['owner' => Clinic::class, 'id' => $clinic->id, '--at' => '2026-09-28T13:00:00+02:00', '--date' => '2026-12-24', '--days' => 1])
        ->expectsOutputToContain('Closed, opens 2026-10-05 08:00')
        ->expectsTable(['Date', 'Weekday', 'Source', 'Label', 'Hours'], [['2026-12-24', 'Thursday', 'exception', 'Eve', 'Closed']])
        ->assertSuccessful();
});

it('rejects unknown owners and non-owner models', function (): void {
    $this->artisan('opening-hours:show', ['owner' => 'nope', 'id' => 1])->expectsOutputToContain('does not implement')->assertFailed();
    $this->artisan('opening-hours:show', ['owner' => NotAnOwner::class, 'id' => 1])->assertFailed();
    $this->artisan('opening-hours:show', ['owner' => Clinic::class, 'id' => 999])->expectsOutputToContain('No [')->assertFailed();
});

it('prunes with options, config defaults, a dry run, or nothing configured', function (): void {
    CarbonImmutable::setTestNow('2026-09-26 12:00:00');
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours(['exceptions' => [['date' => '2026-01-01'], ['date' => '2026-12-24']]]);

    $this->artisan('opening-hours:prune')->expectsOutputToContain('Nothing to prune')->assertSuccessful();
    $this->artisan('opening-hours:prune', ['--exceptions-after-days' => '30', '--dry-run' => true])
        ->expectsOutputToContain('Would prune 1 past exception(s)')->assertSuccessful();

    config()->set('opening-hours.prune.exceptions_after_days', 30);
    config()->set('opening-hours.prune.trashed_after_days', 365);
    $this->artisan('opening-hours:prune')->expectsOutputToContain('Pruned 1 past exception(s) and 0 soft-deleted row(s).')->assertSuccessful();

    expect(ExceptionRule::query()->count())->toBe(1);
});

it('prunes with integer options from Artisan::call and refuses invalid ones', function (): void {
    CarbonImmutable::setTestNow('2026-09-26 12:00:00');
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours(['exceptions' => [['date' => '2026-01-01'], ['date' => '2026-12-24']]]);

    expect(Artisan::call('opening-hours:prune', ['--exceptions-after-days' => 30, '--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Would prune 1 past exception(s)');

    $this->artisan('opening-hours:prune', ['--exceptions-after-days' => 'abc'])
        ->expectsOutputToContain('--exceptions-after-days must be a whole number of days')->assertFailed();
    $this->artisan('opening-hours:prune', ['--trashed-after-days' => '-5'])
        ->expectsOutputToContain('--trashed-after-days must be a whole number of days')->assertFailed();

    expect(ExceptionRule::query()->count())->toBe(2);
});

it('treats an empty prune env value as off', function (): void {
    // `OPENING_HOURS_PRUNE_EXCEPTIONS_AFTER_DAYS=` in .env reaches the config as '' — not a crash.
    config()->set('opening-hours.prune.exceptions_after_days', '');
    config()->set('opening-hours.prune.trashed_after_days', '');

    $this->artisan('opening-hours:prune')->expectsOutputToContain('Nothing to prune')->assertSuccessful();
});

it('materializes inline or queues per calendar, only when enabled', function (): void {
    Bus::fake([MaterializeIntervalsJob::class]);
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours(['week' => ['monday' => ['08:00-12:00']]]);

    $this->artisan('opening-hours:materialize')->expectsOutputToContain('disabled')->assertSuccessful();

    config()->set('opening-hours.materialize.enabled', true);
    $this->artisan('opening-hours:materialize')->expectsOutputToContain('Queued 1 calendar(s).')->assertSuccessful();
    Bus::assertDispatched(MaterializeIntervalsJob::class);

    $this->artisan('opening-hours:materialize', ['--sync' => true, '--calendar' => [(string) $clinic->openingHoursCalendar()->id]])
        ->expectsOutputToContain('Materialized 1 calendar(s).')->assertSuccessful();

    expect(Interval::query()->count())->toBeGreaterThan(0);
});
